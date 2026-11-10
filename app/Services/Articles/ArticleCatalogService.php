<?php

declare(strict_types=1);

namespace App\Services\Articles;

use App\Enums\PermissionCode;
use App\Models\CategoriaArticulo;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\ArticleCatalogRules;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ArticleCatalogService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions) {}

    public function categories(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->entries($actor, CategoriaArticulo::class, $filters);
    }

    public function units(User $actor, array $filters): LengthAwarePaginator
    {
        return $this->entries($actor, UnidadMedida::class, $filters);
    }

    public function saveCategory(User $actor, array $data, ?CategoriaArticulo $category = null): CategoriaArticulo
    {
        return $this->save($actor, $category ?? new CategoriaArticulo(), $data);
    }

    public function saveUnit(User $actor, array $data, ?UnidadMedida $unit = null): UnidadMedida
    {
        return $this->save($actor, $unit ?? new UnidadMedida(), $data);
    }

    public function setActive(User $actor, CategoriaArticulo|UnidadMedida $entry, bool $active): void
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
        DB::transaction(function () use ($actor, $entry, $active): void {
            $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
            $locked = $entry->newQuery()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $locked->forceFill(['activo' => $active])->save();
        });
    }

    private function entries(User $actor, string $model, array $filters): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
        $term = trim((string) ($filters['q'] ?? ''));

        return $model::query()->withCount('articulos')
            ->when($term !== '', function ($query) use ($term, $model): void {
                $term = '%'.addcslashes($term, '\\%_').'%';
                $query->where(function ($nested) use ($term, $model): void {
                    $nested->where('nombre', 'ilike', $term)->orWhere('codigo', 'ilike', $term);
                    if ($model === UnidadMedida::class) {
                        $nested->orWhere('simbolo', 'ilike', $term);
                    }
                });
            })
            ->when(($filters['activo'] ?? '') === 'activos', fn ($query) => $query->where('activo', true))
            ->when(($filters['activo'] ?? '') === 'inactivos', fn ($query) => $query->where('activo', false))
            ->orderBy('nombre')->orderBy('id')->paginate(15)->withQueryString();
    }

    private function save(User $actor, CategoriaArticulo|UnidadMedida $entry, array $data): CategoriaArticulo|UnidadMedida
    {
        $this->permissions->authorize($actor, PermissionCode::ArticlesManage);

        try {
            return DB::transaction(function () use ($actor, $entry, $data) {
                $this->permissions->authorize($actor, PermissionCode::ArticlesManage);
                $locked = $entry->exists ? $entry->newQuery()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail() : $entry;
                $rules = $locked instanceof UnidadMedida ? ArticleCatalogRules::unit($locked) : ArticleCatalogRules::category($locked);
                $values = Validator::make(ArticleCatalogRules::normalize($data), $rules, ArticleCatalogRules::messages())->validate();

                if ($locked instanceof UnidadMedida && $locked->exists && $locked->articulos()->exists()) {
                    foreach (['codigo', 'simbolo', 'magnitud'] as $field) {
                        if ($values[$field] !== $locked->{$field}) {
                            throw ValidationException::withMessages([$field => 'Esta unidad ya tiene artículos asociados. Su código, símbolo y tipo de medida deben conservarse.']);
                        }
                    }
                }

                $locked->forceFill($values);
                if (! $locked->exists) {
                    $locked->activo = true;
                }
                $locked->save();

                return $locked->fresh();
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['nombre' => 'Ya existe un registro con el mismo código, nombre o símbolo. Revisa los datos antes de guardar.']);
            }

            throw $exception;
        }
    }
}
