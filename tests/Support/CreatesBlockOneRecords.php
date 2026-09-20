<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockOneRecords
{
    protected function roleData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'administrador',
            'nombre' => 'Administrador',
        ], $overrides);
    }

    protected function createRole(array $overrides = []): int
    {
        return (int) DB::table('roles')->insertGetId($this->roleData($overrides));
    }

    protected function permissionData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'ventas.crear',
            'nombre' => 'Crear ventas',
            'modulo' => 'ventas',
        ], $overrides);
    }

    protected function createPermission(array $overrides = []): int
    {
        return (int) DB::table('permisos')->insertGetId($this->permissionData($overrides));
    }

    protected function userData(int $roleId, array $overrides = []): array
    {
        return array_merge([
            'rol_id' => $roleId,
            'nombre' => 'Hardy',
            'apellido' => 'Vega',
            'nombre_usuario' => 'cajero',
            'password' => 'test-password-placeholder',
        ], $overrides);
    }

    protected function createUser(int $roleId, array $overrides = []): int
    {
        return (int) DB::table('usuarios')->insertGetId($this->userData($roleId, $overrides));
    }

    protected function attachPermission(int $roleId, int $permissionId): void
    {
        DB::table('rol_permiso')->insert([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);
    }
}
