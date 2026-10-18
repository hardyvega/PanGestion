<?php

namespace Tests\Support;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

trait BlockTwelveTestSupport
{
    private int $testSequence = 0;

    protected function b12User(array $permissions, string $prefix = 'b12_user'): User
    {
        $this->testSequence++;
        $suffix = $this->testSequence;
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => $prefix.'_role_'.$suffix,
            'nombre' => 'Rol B12 '.$suffix,
            'descripcion' => null,
            'activo' => true,
        ]);
        $userId = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Usuario B12',
            'apellido' => 'Prueba',
            'nombre_usuario' => $prefix.'_'.$suffix,
            'correo' => $prefix.'_'.$suffix.'@example.test',
            'password' => Hash::make('clave-de-prueba'),
            'activo' => true,
            'ultimo_acceso' => null,
            'remember_token' => null,
        ]);
        foreach ($permissions as $permission) {
            $permissionId = (int) DB::table('permisos')->insertGetId([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'descripcion' => $permission->description(),
                'activo' => true,
            ]);
            DB::table('rol_permiso')->insert([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
            ]);
        }

        return User::query()->findOrFail($userId);
    }

    protected function b12Manager(): User
    {
        return $this->b12User([
            PermissionCode::PersonnelView,
            PermissionCode::PersonnelManage,
            PermissionCode::HourlyRatesView,
            PermissionCode::HourlyRatesManage,
            PermissionCode::HourlyPayView,
            PermissionCode::AttendanceMark,
            PermissionCode::AttendanceManage,
        ], 'b12_manager');
    }
}
