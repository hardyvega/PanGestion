<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Enums\PermissionCode;
use App\Models\Articulo;
use App\Models\Produccion;
use App\Models\Receta;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductionAdministrationService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionView);
        $term = trim((string) ($filters['q'] ?? ''));
        $status = (string) ($filters['estado'] ?? 'todos');

        return Produccion::query()
            ->with(['receta.articulo', 'responsable'])
            ->withCount(['detalle', 'consumos'])
            ->when($status !== 'todos', static fn ($query) => $query->where('estado', $status))
            ->when($term !== '', static function ($query) use ($term): void {
                $escaped = addcslashes($term, '\\%_');
                $query->where(function ($nested) use ($escaped, $term): void {
                    if (ctype_digit($term)) {
                        $nested->where('producciones.id', (int) $term);
                    }
                    $nested->orWhereHas('receta.articulo', static fn ($article) => $article
                        ->where('nombre', 'ilike', '%'.$escaped.'%')
                        ->orWhere('sku', 'ilike', '%'.$escaped.'%'));
                });
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();
    }

    public function show(User $actor, Produccion $production): Produccion
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionView);

        return $production->load([
            'receta.articulo.unidad',
            'receta.detalles.componente.unidad',
            'responsable',
            'creador',
            'detalle.articulo.unidad',
            'consumos.componente.unidad',
            'consumos.detalleReceta',
        ]);
    }

    /** @return array<int, Receta> */
    public function activeRecipes(User $actor): array
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionManage);

        return Receta::query()
            ->where('activa', true)
            ->with(['articulo.unidad', 'detalles.componente.unidad'])
            ->orderBy('articulo_id')->orderBy('version')
            ->get()
            ->all();
    }

    public function create(User $actor, array $data): Produccion
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionManage);

        return DB::transaction(function () use ($actor, $data): Produccion {
            $recipeId = (int) $data['receta_id'];
            $recipe = Receta::query()->whereKey($recipeId)->lockForUpdate()->first();
            if ($recipe === null) {
                throw (new ModelNotFoundException())->setModel(Receta::class, [$recipeId]);
            }
            if (! $recipe->activa) {
                throw ValidationException::withMessages(['receta_id' => 'Solo puedes iniciar una producción con la receta vigente.']);
            }

            $context = $this->recipeContext($recipe);
            $output = $this->positiveQuantity($data['cantidad_producida'] ?? null, 'Indica una cantidad producida positiva.');
            $actuals = $this->actualConsumptions($data['consumos'] ?? [], $context['details']);

            $now = now();
            $production = new Produccion();
            $production->forceFill([
                'receta_id' => $recipe->getKey(),
                'usuario_responsable_id' => $actor->getKey(),
                'usuario_creador_id' => $actor->getKey(),
                'estado' => 'borrador',
                'observacion' => $this->nullableText($data['observacion'] ?? null),
            ]);
            $production->save();

            $product = $context['product'];
            $unit = $context['productUnit'];
            $detailId = (int) DB::table('detalle_producciones')->insertGetId([
                'produccion_id' => $production->getKey(),
                'articulo_id' => $product->id,
                'articulo_sku_snapshot' => $product->sku,
                'articulo_nombre_snapshot' => $product->nombre,
                'unidad_codigo_snapshot' => $unit->codigo,
                'unidad_simbolo_snapshot' => $unit->simbolo,
                'cantidad_teorica' => $recipe->rendimiento_cantidad,
                'cantidad_real' => $output,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rows = [];
            foreach ($context['details'] as $recipeDetail) {
                $component = $context['components']->get((int) $recipeDetail->articulo_componente_id);
                $componentUnit = $context['units']->get((int) $component->unidad_id);
                $rows[] = [
                    'produccion_id' => $production->getKey(),
                    'detalle_receta_id' => $recipeDetail->id,
                    'articulo_componente_id' => $component->id,
                    'articulo_sku_snapshot' => $component->sku,
                    'articulo_nombre_snapshot' => $component->nombre,
                    'unidad_codigo_snapshot' => $componentUnit->codigo,
                    'unidad_simbolo_snapshot' => $componentUnit->simbolo,
                    'cantidad_teorica' => $this->scaledTheoreticalQuantity((string) $recipeDetail->cantidad, $output, (string) $recipe->rendimiento_cantidad),
                    'cantidad_real' => $actuals[(int) $recipeDetail->id],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('consumos_produccion')->insert($rows);

            return $production->fresh(['receta.articulo']);
        });
    }

    public function updateDraft(User $actor, Produccion $production, array $data): Produccion
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionManage);

        return DB::transaction(function () use ($production, $data): Produccion {
            $locked = Produccion::query()->whereKey($production->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->estado !== 'borrador') {
                throw ValidationException::withMessages(['production' => 'Solo puedes editar producciones en borrador.']);
            }
            $output = $this->positiveQuantity($data['cantidad_producida'] ?? null, 'Indica una cantidad producida positiva.');
            $consumptions = DB::table('consumos_produccion')->where('produccion_id', $locked->getKey())->get();
            $actuals = $this->actualConsumptions($data['consumos'] ?? [], $consumptions, 'detalle_receta_id');
            $now = now();

            DB::table('detalle_producciones')->where('produccion_id', $locked->getKey())->update([
                'cantidad_real' => $output,
                'updated_at' => $now,
            ]);
            foreach ($consumptions as $consumption) {
                DB::table('consumos_produccion')->where('id', $consumption->id)->update([
                    'cantidad_real' => $actuals[(int) $consumption->detalle_receta_id],
                    'updated_at' => $now,
                ]);
            }
            $locked->forceFill(['observacion' => $this->nullableText($data['observacion'] ?? null)])->save();

            return $locked->fresh(['receta.articulo']);
        });
    }

    /** @return array{recipe: Receta, product: object, productUnit: object, details: \Illuminate\Support\Collection, components: \Illuminate\Support\Collection, units: \Illuminate\Support\Collection} */
    private function recipeContext(Receta $recipe): array
    {
        $product = DB::table('articulos')->where('id', $recipe->articulo_id)->lockForUpdate()->first();
        $productUnit = $product === null ? null : DB::table('unidades_medida')->where('id', $product->unidad_id)->first();
        $details = DB::table('detalle_recetas')->where('receta_id', $recipe->getKey())->orderBy('id')->lockForUpdate()->get();
        if ($product === null || $productUnit === null || ! $product->activo || $product->tipo_articulo !== 'elaborado' || $details->isEmpty()) {
            throw ValidationException::withMessages(['receta_id' => 'La receta debe tener un producto elaborado activo y al menos un ingrediente.']);
        }

        $componentIds = $details->pluck('articulo_componente_id')->map(static fn ($id): int => (int) $id)->all();
        $components = DB::table('articulos')->whereIn('id', $componentIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $units = DB::table('unidades_medida')->whereIn('id', $components->pluck('unidad_id')->all())->get()->keyBy('id');
        foreach ($details as $detail) {
            $component = $components->get((int) $detail->articulo_componente_id);
            if ($component === null || ! $component->activo || $component->tipo_articulo !== 'materia_prima' || $units->get((int) $component->unidad_id) === null) {
                throw ValidationException::withMessages(['receta_id' => 'Todos los ingredientes deben ser materias primas activas con unidad.']);
            }
        }

        return compact('recipe', 'product', 'productUnit', 'details', 'components', 'units');
    }

    /** @return array<int, string> */
    private function actualConsumptions(mixed $raw, iterable $details, string $key = 'id'): array
    {
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['consumos' => 'Completa los consumos de la producción.']);
        }
        $actuals = [];
        foreach ($details as $detail) {
            $detailKey = (int) $detail->{$key};
            $value = $raw[$detailKey] ?? null;
            $actuals[$detailKey] = $this->nonNegativeQuantity($value, 'Las cantidades consumidas no pueden ser negativas.');
        }

        return $actuals;
    }

    private function positiveQuantity(mixed $value, string $message): string
    {
        try {
            $quantity = Decimal::quantity((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cantidad_producida' => $message]);
        }
        if (Decimal::compare($quantity, '0.000', 3) <= 0) {
            throw ValidationException::withMessages(['cantidad_producida' => $message]);
        }

        return $quantity;
    }

    private function nonNegativeQuantity(mixed $value, string $message): string
    {
        try {
            $quantity = Decimal::quantity((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['consumos' => $message]);
        }
        if (Decimal::compare($quantity, '0.000', 3) < 0) {
            throw ValidationException::withMessages(['consumos' => $message]);
        }

        return $quantity;
    }

    private function scaledTheoreticalQuantity(string $componentQuantity, string $output, string $yield): string
    {
        $scaled = Decimal::multiply($componentQuantity, $output, 6);

        return Decimal::round(bcdiv($scaled, $yield, 6), 3);
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
