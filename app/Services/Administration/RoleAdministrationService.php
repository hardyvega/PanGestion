<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\PermissionCode;
use App\Exceptions\OperationalAdminException;
use App\Models\Rol;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RoleAdministrationService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly OperationalAdminGuard $operationalAdmins,
    ) {
    }

    public function list(User $actor, string $term = '', string $active = 'todos')
    {
        $this->permissions->authorize($actor, PermissionCode::RolesView);

        return Rol::query()
            ->withCount(['usuarios', 'permisos'])
            ->when($term !== '', static function ($query) use ($term): void {
                $escaped = addcslashes($term, '\\%_');
                $query->where(static function ($nested) use ($escaped): void {
                    $nested->where('codigo', 'ilike', '%'.$escaped.'%')
                        ->orWhere('nombre', 'ilike', '%'.$escaped.'%');
                });
            })
            ->when($active === 'activos', static fn ($query) => $query->where('activo', true))
            ->when($active === 'inactivos', static fn ($query) => $query->where('activo', false))
            ->orderBy('nombre')->orderBy('id')
            ->paginate(15)
            ->withQueryString();
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Rol
    {
        $this->permissions->authorize($actor, PermissionCode::RolesManage);

        $role = new Rol();
        $role->forceFill([
            'codigo' => strtolower(trim((string) $data['codigo'])),
            'nombre' => trim((string) $data['nombre']),
            'descripcion' => $this->nullableText($data['descripcion'] ?? null),
            'activo' => true,
        ]);
        $role->save();

        return $role->fresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Rol $role, array $data): Rol
    {
        $this->permissions->authorize($actor, PermissionCode::RolesManage);

        return DB::transaction(function () use ($actor, $role, $data): Rol {
            $this->operationalAdmins->lockAuthorizationState();
            $locked = $this->lockRole($role->getKey());
            DB::table('roles')->where('id', $locked->getKey())->update([
                'codigo' => strtolower(trim((string) $data['codigo'])),
                'nombre' => trim((string) $data['nombre']),
                'descripcion' => $this->nullableText($data['descripcion'] ?? null),
                'updated_at' => now(),
            ]);

            $this->operationalAdmins->assertAtLeastOne();
            if ((int) $actor->rol_id === (int) $locked->getKey()) {
                $this->operationalAdmins->assertActorRemains($actor);
            }

            return Rol::query()->findOrFail($locked->getKey());
        });
    }

    public function setActive(User $actor, Rol $role, bool $active): Rol
    {
        $this->permissions->authorize($actor, PermissionCode::RolesManage);

        return DB::transaction(function () use ($actor, $role, $active): Rol {
            $this->operationalAdmins->lockAuthorizationState();
            $locked = $this->lockRole($role->getKey());
            DB::table('roles')->where('id', $locked->getKey())->update([
                'activo' => $active,
                'updated_at' => now(),
            ]);

            $this->operationalAdmins->assertAtLeastOne();
            if ((int) $actor->rol_id === (int) $locked->getKey()) {
                $this->operationalAdmins->assertActorRemains($actor);
            }

            return Rol::query()->findOrFail($locked->getKey());
        });
    }

    /** @param list<int> $permissionIds */
    public function syncPermissions(User $actor, Rol $role, array $permissionIds): Rol
    {
        $this->permissions->authorize($actor, PermissionCode::RolePermissionsManage);

        return DB::transaction(function () use ($actor, $role, $permissionIds): Rol {
            $this->operationalAdmins->lockAuthorizationState();
            $locked = $this->lockRole($role->getKey());
            $permissionIds = array_values(array_unique(array_map('intval', $permissionIds)));

            $validCount = DB::table('permisos')
                ->where('activo', true)
                ->whereIn('id', $permissionIds)
                ->count();
            if ($validCount !== count($permissionIds)) {
                throw new OperationalAdminException('Solo puedes asignar permisos existentes y activos.');
            }

            DB::table('rol_permiso')->where('rol_id', $locked->getKey())->delete();
            if ($permissionIds !== []) {
                DB::table('rol_permiso')->insert(array_map(
                    static fn (int $permissionId): array => [
                        'rol_id' => $locked->getKey(),
                        'permiso_id' => $permissionId,
                    ],
                    $permissionIds,
                ));
            }

            $this->operationalAdmins->assertAtLeastOne();
            if ((int) $actor->rol_id === (int) $locked->getKey()) {
                $this->operationalAdmins->assertActorRemains($actor);
            }

            return Rol::query()->findOrFail($locked->getKey());
        });
    }

    private function lockRole(int $id): Rol
    {
        $row = DB::table('roles')->where('id', $id)->lockForUpdate()->first();
        if ($row === null) {
            throw (new ModelNotFoundException())->setModel(Rol::class, [$id]);
        }

        return Rol::query()->findOrFail($id);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
