<?php

declare(strict_types=1);

namespace App\Services\Articles;

use App\Models\Articulo;
use App\Models\CategoriaArticulo;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Enums\PermissionCode;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ArticleAdministrationService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Articulo
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $data, &$storedPath): Articulo {
                $this->lockCatalogReferences($data);
                $price = $this->nullableMoney($data['precio_venta'] ?? null);
                $storedPath = $this->storeImage($data['imagen'] ?? null);

                $article = new Articulo();
                $article->forceFill([
                    'sku' => trim((string) $data['sku']),
                    'nombre' => trim((string) $data['nombre']),
                    'descripcion' => $this->nullableText($data['descripcion'] ?? null),
                    'tipo_articulo' => (string) $data['tipo_articulo'],
                    'categoria_articulo_id' => (int) $data['categoria_articulo_id'],
                    'unidad_id' => (int) $data['unidad_id'],
                    'codigo_barras' => $this->nullableText($data['codigo_barras'] ?? null),
                    'precio_venta_actual' => $price,
                    'stock_minimo' => Decimal::quantity((string) $data['stock_minimo']),
                    'activo' => true,
                    'imagen_ruta' => $storedPath,
                ]);
                $article->save();

                if ($price !== null) {
                    $this->appendPriceHistory($article, $actor, null, $price, $data['motivo_precio'] ?? null);
                }

                return $article->fresh(['categoria', 'unidad']);
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('public')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Articulo $article, array $data): Articulo
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
        $newPath = null;
        $oldPath = null;

        try {
            $updated = DB::transaction(function () use ($actor, $article, $data, &$newPath, &$oldPath): Articulo {
                $locked = Articulo::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
                $this->lockCatalogReferences($data, $locked);
                $oldPath = $locked->imagen_ruta;
                $newPath = $this->storeImage($data['imagen'] ?? null);
                $nextPath = $newPath ?? ((bool) ($data['quitar_imagen'] ?? false) ? null : $oldPath);
                $previousPrice = $locked->getRawOriginal('precio_venta_actual');
                $nextPrice = array_key_exists('precio_venta', $data)
                    ? $this->nullableMoney($data['precio_venta'])
                    : ($previousPrice === null ? null : Decimal::money($previousPrice));

                $locked->forceFill([
                    'sku' => trim((string) $data['sku']),
                    'nombre' => trim((string) $data['nombre']),
                    'descripcion' => $this->nullableText($data['descripcion'] ?? null),
                    'tipo_articulo' => (string) $data['tipo_articulo'],
                    'categoria_articulo_id' => (int) $data['categoria_articulo_id'],
                    'unidad_id' => (int) $data['unidad_id'],
                    'codigo_barras' => $this->nullableText($data['codigo_barras'] ?? null),
                    'stock_minimo' => Decimal::quantity((string) $data['stock_minimo']),
                    'imagen_ruta' => $nextPath,
                ]);
                $locked->save();

                if ($this->pricesDiffer($previousPrice, $nextPrice)) {
                    $locked->forceFill(['precio_venta_actual' => $nextPrice]);
                    $locked->save();
                    $this->appendPriceHistory($locked, $actor, $previousPrice, $nextPrice, $data['motivo_precio'] ?? null);
                }

                return $locked->fresh(['categoria', 'unidad']);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        $this->deleteOwnedImage($oldPath, $updated->imagen_ruta);

        return $updated;
    }

    public function setActive(User $actor, Articulo $article, bool $active): Articulo
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
        $article->forceFill(['activo' => $active])->save();

        return $article->fresh(['categoria', 'unidad']);
    }

    /** @param array<string, mixed> $data */
    public function updatePrice(User $actor, Articulo $article, array $data): Articulo
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
        return DB::transaction(function () use ($actor, $article, $data): Articulo {
            $locked = Articulo::query()->whereKey($article->getKey())->lockForUpdate()->firstOrFail();
            $previousPrice = $locked->getRawOriginal('precio_venta_actual');
            $nextPrice = $this->nullableMoney($data['precio_venta'] ?? null);

            if (! $this->pricesDiffer($previousPrice, $nextPrice)) {
                return $locked->fresh(['categoria', 'unidad']);
            }

            $locked->forceFill(['precio_venta_actual' => $nextPrice])->save();
            $this->appendPriceHistory($locked, $actor, $previousPrice, $nextPrice, $data['motivo_precio'] ?? null);

            return $locked->fresh(['categoria', 'unidad']);
        });
    }

    private function lockCatalogReferences(array $data, ?Articulo $article = null): void
    {
        foreach (['categoria_articulo_id' => CategoriaArticulo::class, 'unidad_id' => UnidadMedida::class] as $field => $model) {
            $entry = $model::query()->whereKey((int) ($data[$field] ?? 0))->lockForUpdate()->first();
            if ($entry === null || (! $entry->activo && ($article === null || (int) $article->{$field} !== (int) $entry->getKey()))) {
                throw ValidationException::withMessages([$field => 'La categoría o unidad seleccionada no existe o ya no está disponible.']);
            }
        }
    }

    private function appendPriceHistory(
        Articulo $article,
        User $actor,
        ?string $previousPrice,
        ?string $nextPrice,
        mixed $reason,
    ): void {
        DB::table('historial_precios_venta')->insert([
            'articulo_id' => $article->getKey(),
            'precio_anterior' => $previousPrice,
            'precio_nuevo' => $nextPrice,
            'vigente_desde' => now(),
            'usuario_id' => $actor->getKey(),
            'motivo' => $this->nullableText($reason),
            'created_at' => now(),
        ]);
    }

    private function storeImage(mixed $image): ?string
    {
        return $image instanceof UploadedFile
            ? $image->store('articulos', ['disk' => 'public'])
            : null;
    }

    private function deleteOwnedImage(?string $oldPath, mixed $currentPath): void
    {
        if ($oldPath !== null && $oldPath !== $currentPath && str_starts_with($oldPath, 'articulos/')) {
            Storage::disk('public')->delete($oldPath);
        }
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function nullableMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Decimal::money((string) $value);
    }

    private function pricesDiffer(?string $previous, ?string $next): bool
    {
        return ($previous === null ? null : Decimal::money($previous)) !== $next;
    }
}
