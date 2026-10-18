<?php

declare(strict_types=1);

namespace App\Services\Administration;

use App\Enums\PermissionCode;
use App\Exceptions\OperationalAdminException;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Support\Facades\DB;

final class OperationalAdminGuard
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    /** @return list<PermissionCode> */
    public static function requiredPermissions(): array
    {
        return [
            PermissionCode::UsersView,
            PermissionCode::UsersManage,
            PermissionCode::RolesView,
            PermissionCode::RolesManage,
            PermissionCode::PermissionsView,
            PermissionCode::RolePermissionsManage,
        ];
    }

    public function lockAuthorizationState(): void
    {
        DB::table('usuarios')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('roles')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('permisos')
            ->whereIn('codigo', $this->codes())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->lockForUpdate()->get();
    }

    public function assertAtLeastOne(): void
    {
        $required = count(self::requiredPermissions());
        $operationalAdmins = DB::table('usuarios as usuarios')
            ->join('roles as roles', 'roles.id', '=', 'usuarios.rol_id')
            ->join('rol_permiso as rol_permiso', 'rol_permiso.rol_id', '=', 'roles.id')
            ->join('permisos as permisos', 'permisos.id', '=', 'rol_permiso.permiso_id')
            ->where('usuarios.activo', true)
            ->where('roles.activo', true)
            ->where('permisos.activo', true)
            ->whereIn('permisos.codigo', $this->codes())
            ->groupBy('usuarios.id')
            ->havingRaw('COUNT(DISTINCT permisos.codigo) = ?', [$required])
            ->get(['usuarios.id'])
            ->count();

        if ($operationalAdmins < 1) {
            throw new OperationalAdminException(
                'La operación dejaría el sistema sin un administrador operativo.',
            );
        }
    }

    public function assertActorRemains(User $actor): void
    {
        $freshActor = User::query()->findOrFail($actor->getKey());

        if (! $freshActor->activo || ! $this->permissions->allowsAll($freshActor, self::requiredPermissions())) {
            throw new OperationalAdminException(
                'No puedes retirar de tu propia cuenta la condición de administrador operativo.',
            );
        }
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_map(
            static fn (PermissionCode $permission): string => $permission->value,
            self::requiredPermissions(),
        );
    }
}
