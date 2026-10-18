<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PermissionGateTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW_RECEIPTS = 'inventario.recepciones.ver';

    public function test_recognized_ability_with_effective_permission_is_allowed(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $user = $this->createUserWithPermission($permission);

        $this->assertTrue(Gate::forUser($user)->allows($permission->value));
    }

    public function test_recognized_ability_without_effective_permission_is_denied(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $roleId = $this->createRole('operador');
        $user = $this->createUser($roleId);
        $this->createPermission($permission);

        $this->assertFalse(Gate::forUser($user)->allows($permission->value));
    }

    public function test_unknown_ability_is_left_to_its_own_gate_definition(): void
    {
        $roleId = $this->createRole('operador');
        $user = $this->createUser($roleId);

        Gate::define('reportes.operativos.ver', static fn (User $candidate): bool => $candidate->activo);
        Gate::define('reportes.operativos.eliminar', static fn (): bool => false);

        $this->assertTrue(Gate::forUser($user)->allows('reportes.operativos.ver'));
        $this->assertFalse(Gate::forUser($user)->allows('reportes.operativos.eliminar'));
    }

    public function test_dueno_role_has_no_automatic_gate_bypass(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $roleId = $this->createRole('dueno');
        $user = $this->createUser($roleId);
        $this->createPermission($permission);

        $this->assertFalse(Gate::forUser($user)->allows($permission->value));
    }

    private function createUserWithPermission(PermissionCode $permission): User
    {
        $roleId = $this->createRole('operador');
        $user = $this->createUser($roleId);
        $permissionId = $this->createPermission($permission);

        DB::table('rol_permiso')->insert([
            'rol_id' => $roleId,
            'permiso_id' => $permissionId,
        ]);

        return $user;
    }

    private function createRole(string $code): int
    {
        return (int) DB::table('roles')->insertGetId([
            'codigo' => $code,
            'nombre' => ucfirst($code),
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUser(int $roleId): User
    {
        $id = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Usuario',
            'apellido' => 'Gate',
            'nombre_usuario' => 'usuario_gate',
            'correo' => 'gate@example.test',
            'password' => Hash::make('clave-de-prueba'),
            'activo' => true,
            'ultimo_acceso' => null,
            'remember_token' => null,
        ]);

        return User::query()->findOrFail($id);
    }

    private function createPermission(PermissionCode $permission): int
    {
        return (int) DB::table('permisos')->insertGetId([
            'codigo' => $permission->value,
            'nombre' => 'Permiso '.$permission->value,
            'modulo' => explode('.', $permission->value, 2)[0],
            'descripcion' => 'Descripcion de '.$permission->value,
            'activo' => true,
        ]);
    }
}
