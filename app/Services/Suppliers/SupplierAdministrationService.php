<?php

declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Enums\PermissionCode;
use App\Models\Articulo;
use App\Models\ArticuloProveedor;
use App\Models\HistorialCostoArticulo;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierAdministrationService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function list(User $actor, array $filters): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersView);

        $term = trim((string) ($filters['q'] ?? ''));
        $active = (string) ($filters['activo'] ?? 'todos');

        return Proveedor::query()
            ->withCount(['articulos as articulos_activos_count' => fn ($query) => $query->where('articulo_proveedor.activo', true)])
            ->when($term !== '', static function ($query) use ($term): void {
                $escaped = addcslashes($term, '\\%_');
                $query->where(static function ($builder) use ($escaped, $term): void {
                    $builder->where('codigo', 'ilike', '%'.$escaped.'%')
                        ->orWhere('nombre', 'ilike', '%'.$escaped.'%')
                        ->orWhere('nombre_contacto', 'ilike', '%'.$escaped.'%')
                        ->orWhere('correo', 'ilike', '%'.$escaped.'%')
                        ->orWhere('telefono', 'ilike', '%'.$escaped.'%');
                    if (ctype_digit($term)) {
                        $builder->orWhere('id', (int) $term);
                    }
                });
            })
            ->when($active === 'activos', static fn ($query) => $query->where('activo', true))
            ->when($active === 'inactivos', static fn ($query) => $query->where('activo', false))
            ->orderBy('nombre')->orderBy('id')
            ->paginate(15)
            ->withQueryString();
    }

    public function create(User $actor, array $data): Proveedor
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersManage);

        return DB::transaction(function () use ($data): Proveedor {
            $supplier = new Proveedor();
            $supplier->forceFill($this->supplierAttributes($data));
            $supplier->activo = true;
            $supplier->save();

            return $supplier->fresh();
        });
    }

    public function update(User $actor, Proveedor $supplier, array $data): Proveedor
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersManage);

        return DB::transaction(function () use ($supplier, $data): Proveedor {
            $locked = Proveedor::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill($this->supplierAttributes($data));
            $locked->save();

            return $locked->fresh();
        });
    }

    public function setActive(User $actor, Proveedor $supplier, bool $active): Proveedor
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersManage);

        return DB::transaction(function () use ($supplier, $active): Proveedor {
            $locked = Proveedor::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['activo' => $active])->save();

            return $locked->fresh();
        });
    }

    public function attachArticle(User $actor, Proveedor $supplier, int $articleId, ?string $supplierCode): ArticuloProveedor
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersManage);

        return DB::transaction(function () use ($supplier, $articleId, $supplierCode): ArticuloProveedor {
            $supplier = Proveedor::query()->whereKey($supplier->getKey())->lockForUpdate()->firstOrFail();
            $article = Articulo::query()->whereKey($articleId)->lockForUpdate()->firstOrFail();

            if (ArticuloProveedor::query()->where('articulo_id', $article->getKey())->where('proveedor_id', $supplier->getKey())->exists()) {
                throw ValidationException::withMessages(['articulo_id' => 'El artículo ya está asociado a este proveedor.']);
            }

            $relation = new ArticuloProveedor();
            $relation->forceFill([
                'articulo_id' => $article->getKey(),
                'proveedor_id' => $supplier->getKey(),
                'codigo_articulo_proveedor' => $supplierCode,
                'activo' => true,
            ]);
            $relation->save();

            return $relation->load('articulo');
        });
    }

    public function detachArticle(User $actor, Proveedor $supplier, Articulo $article): void
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersManage);

        DB::transaction(function () use ($supplier, $article): void {
            $relation = ArticuloProveedor::query()
                ->where('proveedor_id', $supplier->getKey())
                ->where('articulo_id', $article->getKey())
                ->lockForUpdate()
                ->first();

            if ($relation === null) {
                throw (new ModelNotFoundException())->setModel(ArticuloProveedor::class);
            }

            $relation->delete();
        });
    }

    public function supplierArticles(User $actor, Proveedor $supplier): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersView);

        return $supplier->articulos()
            ->with(['categoria', 'unidad'])
            ->orderBy('articulos.nombre')->orderBy('articulos.id')
            ->paginate(10, ['articulos.*'], 'articulos_page')
            ->withQueryString();
    }

    public function supplierCosts(User $actor, Proveedor $supplier): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::InventoryCostsView);

        return $this->costQuery()
            ->where('costos.proveedor_id', $supplier->getKey())
            ->with(['articulo', 'detalleRecepcion.recepcion'])
            ->orderByDesc('costos.vigente_desde')->orderByDesc('costos.id')
            ->paginate(10, ['costos.*'], 'costos_page')
            ->withQueryString();
    }

    public function articleSuppliers(User $actor, Articulo $article): \Illuminate\Support\Collection
    {
        $this->permissions->authorize($actor, PermissionCode::SuppliersView);

        return $article->proveedores()
            ->wherePivot('activo', true)
            ->orderBy('proveedores.nombre')->orderBy('proveedores.id')
            ->get();
    }

    public function articleCosts(User $actor, Articulo $article): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::InventoryCostsView);

        return $this->costQuery()
            ->where('costos.articulo_id', $article->getKey())
            ->with(['proveedor', 'detalleRecepcion.recepcion'])
            ->orderByDesc('costos.vigente_desde')->orderByDesc('costos.id')
            ->paginate(10, ['costos.*'], 'costos_page')
            ->withQueryString();
    }

    public function currentCost(User $actor, Articulo $article): ?string
    {
        $this->permissions->authorize($actor, PermissionCode::InventoryCostsView);

        return $this->costQuery()
            ->where('costos.articulo_id', $article->getKey())
            ->where('costos.vigente_desde', '<=', now())
            ->where(static function ($query): void {
                $query->whereNull('costos.detalle_recepcion_mercaderia_id')
                    ->orWhere('recepciones_costo.estado', '<>', ReceiptStatus::Annulled->value);
            })
            ->orderByDesc('costos.vigente_desde')->orderByDesc('costos.id')
            ->value('costos.costo_nuevo');
    }

    private function supplierAttributes(array $data): array
    {
        return [
            'codigo' => strtolower(trim((string) $data['codigo'])),
            'nombre' => trim((string) $data['nombre']),
            'nombre_contacto' => $this->nullableText($data['nombre_contacto'] ?? null),
            'telefono' => $this->nullableText($data['telefono'] ?? null),
            'correo' => $this->nullableText($data['correo'] ?? null),
            'observacion' => $this->nullableText($data['observacion'] ?? null),
        ];
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function costQuery()
    {
        return HistorialCostoArticulo::query()
            ->from('historial_costos_articulo as costos')
            ->leftJoin('detalle_recepciones_mercaderia as detalles_costo', 'detalles_costo.id', '=', 'costos.detalle_recepcion_mercaderia_id')
            ->leftJoin('recepciones_mercaderia as recepciones_costo', 'recepciones_costo.id', '=', 'detalles_costo.recepcion_mercaderia_id')
            ->select('costos.*');
    }
}
