<?php

declare(strict_types=1);

namespace App\Services\Recipes;

use App\Enums\PermissionCode;
use App\Models\Articulo;
use App\Models\Receta;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecipeAdministrationService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesView);
        $term = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['estado'] ?? 'todas');

        return Receta::query()
            ->with('articulo')
            ->withCount('detalles')
            ->when($term !== '', static function ($query) use ($term): void {
                $escaped = addcslashes($term, '\\%_');
                $query->whereHas('articulo', static function ($article) use ($escaped, $term): void {
                    $article->where('nombre', 'ilike', '%'.$escaped.'%')
                        ->orWhere('sku', 'ilike', '%'.$escaped.'%');
                    if (ctype_digit($term)) {
                        $article->orWhere('articulos.id', (int) $term);
                    }
                });
            })
            ->when($status === 'activas', static fn ($query) => $query->where('activa', true))
            ->when($status === 'inactivas', static fn ($query) => $query->where('activa', false))
            ->orderByDesc('activa')
            ->orderBy('articulo_id')
            ->orderByDesc('version')
            ->paginate(15)
            ->withQueryString();
    }

    public function show(User $actor, Receta $recipe): Receta
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesView);

        return $recipe->load([
            'articulo.unidad',
            'detalles.componente.unidad',
            'producciones' => static fn ($query) => $query->latest('id'),
        ]);
    }

    public function create(User $actor, array $data): Receta
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesManage);

        return $this->persistVersion($data, null);
    }

    public function createVersion(User $actor, Receta $previous, array $data): Receta
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesManage);

        return $this->persistVersion($data, $previous);
    }

    public function activate(User $actor, Receta $recipe): Receta
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesManage);

        return DB::transaction(function () use ($recipe): Receta {
            $locked = Receta::query()->whereKey($recipe->getKey())->lockForUpdate()->firstOrFail();
            DB::table('recetas')
                ->where('articulo_id', $locked->articulo_id)
                ->where('id', '<>', $locked->getKey())
                ->where('activa', true)
                ->update(['activa' => false, 'updated_at' => now()]);
            $locked->forceFill(['activa' => true])->save();

            return $locked->fresh(['articulo']);
        });
    }

    /** @return array<int, Articulo> */
    public function productOptions(User $actor): array
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesManage);

        return Articulo::query()
            ->where('tipo_articulo', 'elaborado')
            ->where('activo', true)
            ->with('unidad')
            ->orderBy('nombre')->orderBy('id')
            ->get()
            ->all();
    }

    /** @return array<int, Articulo> */
    public function componentOptions(User $actor): array
    {
        $this->permissions->authorize($actor, PermissionCode::RecipesManage);

        return Articulo::query()
            ->where('tipo_articulo', 'materia_prima')
            ->where('activo', true)
            ->with('unidad')
            ->orderBy('nombre')->orderBy('id')
            ->get()
            ->all();
    }

    private function persistVersion(array $data, ?Receta $previous): Receta
    {
        return DB::transaction(function () use ($data, $previous): Receta {
            $productId = (int) $data['articulo_id'];
            if ($previous !== null && (int) $previous->articulo_id !== $productId) {
                throw ValidationException::withMessages(['articulo_id' => 'La nueva versión debe pertenecer al mismo producto.']);
            }
            $product = Articulo::query()->whereKey($productId)->lockForUpdate()->first();
            if ($product === null) {
                throw (new ModelNotFoundException())->setModel(Articulo::class, [$productId]);
            }
            if (! $product->activo || $product->tipo_articulo !== 'elaborado') {
                throw ValidationException::withMessages(['articulo_id' => 'La receta requiere un artículo elaborado y activo.']);
            }

            $components = $this->normalizeComponents($data['componentes'] ?? []);
            $componentIds = array_keys($components);
            if (in_array($productId, $componentIds, true)) {
                throw ValidationException::withMessages(['componentes' => 'El producto elaborado no puede ser ingrediente de sí mismo.']);
            }

            $articles = Articulo::query()
                ->whereIn('id', $componentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($componentIds as $componentId) {
                $component = $articles->get($componentId);
                if ($component === null || ! $component->activo || $component->tipo_articulo !== 'materia_prima') {
                    throw ValidationException::withMessages(['componentes' => 'Todos los ingredientes deben ser materias primas activas.']);
                }
            }

            $versionRows = Receta::query()
                ->where('articulo_id', $productId)
                ->lockForUpdate()
                ->get(['version']);
            $currentVersion = (int) $versionRows->max('version');
            $version = $previous === null ? $currentVersion + 1 : max($currentVersion + 1, (int) $previous->version + 1);
            $active = (bool) ($data['activa'] ?? true);

            if ($active) {
                DB::table('recetas')
                    ->where('articulo_id', $productId)
                    ->where('activa', true)
                    ->update(['activa' => false, 'updated_at' => now()]);
            }

            $recipe = new Receta();
            $recipe->forceFill([
                'articulo_id' => $productId,
                'version' => $version,
                'rendimiento_cantidad' => Decimal::quantity((string) $data['rendimiento_cantidad']),
                'activa' => $active,
                'observacion' => $this->nullableText($data['observacion'] ?? null),
            ]);
            $recipe->save();

            $now = now();
            $rows = [];
            foreach ($components as $componentId => $quantity) {
                $rows[] = [
                    'receta_id' => $recipe->getKey(),
                    'articulo_componente_id' => $componentId,
                    'cantidad' => $quantity,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('detalle_recetas')->insert($rows);

            return $recipe->fresh(['articulo', 'detalles.componente']);
        });
    }

    /** @return array<int, string> */
    private function normalizeComponents(mixed $components): array
    {
        if (! is_array($components) || $components === []) {
            throw ValidationException::withMessages(['componentes' => 'Agrega al menos un ingrediente.']);
        }

        $normalized = [];
        foreach ($components as $component) {
            if (! is_array($component) || ! isset($component['articulo_componente_id'], $component['cantidad'])) {
                throw ValidationException::withMessages(['componentes' => 'Cada ingrediente requiere artículo y cantidad.']);
            }
            $id = (int) $component['articulo_componente_id'];
            try {
                $quantity = Decimal::quantity((string) $component['cantidad']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['componentes' => 'Las cantidades deben ser positivas y usar hasta tres decimales.']);
            }
            if (Decimal::compare($quantity, '0.000', 3) <= 0 || isset($normalized[$id])) {
                throw ValidationException::withMessages(['componentes' => 'Los ingredientes deben ser únicos y tener cantidad positiva.']);
            }
            $normalized[$id] = $quantity;
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
