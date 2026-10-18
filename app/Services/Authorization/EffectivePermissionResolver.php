<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class EffectivePermissionResolver
{
    public function allows(User $user, PermissionCode $permission): bool
    {
        return $this->allowsAll($user, [$permission]);
    }

    public function allowsAll(User $user, iterable $permissions): bool
    {
        $codes = $this->permissionCodes($permissions);

        if ($codes === []) {
            return true;
        }

        $matchedPermissions = DB::table('usuarios')
            ->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->join('rol_permiso', 'rol_permiso.rol_id', '=', 'roles.id')
            ->join('permisos', 'permisos.id', '=', 'rol_permiso.permiso_id')
            ->where('usuarios.id', $user->getKey())
            ->where('usuarios.activo', true)
            ->where('roles.activo', true)
            ->where('permisos.activo', true)
            ->whereIn('permisos.codigo', $codes)
            ->distinct()
            ->count('permisos.codigo');

        return $matchedPermissions === count($codes);
    }

    public function authorize(User $user, PermissionCode $permission): void
    {
        if (! $this->allows($user, $permission)) {
            throw new AuthorizationException('El usuario no tiene el permiso requerido.');
        }
    }

    public function authorizeAll(User $user, iterable $permissions): void
    {
        if (! $this->allowsAll($user, $permissions)) {
            throw new AuthorizationException('El usuario no tiene todos los permisos requeridos.');
        }
    }

    private function permissionCodes(iterable $permissions): array
    {
        $codes = [];

        foreach ($permissions as $permission) {
            if (! $permission instanceof PermissionCode) {
                throw new InvalidArgumentException('Cada permiso debe ser una instancia de PermissionCode.');
            }

            $codes[$permission->value] = true;
        }

        return array_keys($codes);
    }
}
