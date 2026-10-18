<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdministrationWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_view_permission_can_consult_users_but_cannot_mutate(): void
    {
        $roleId = $this->createRole('consulta');
        $permissionId = $this->createPermission(PermissionCode::UsersView);
        DB::table('rol_permiso')->insert(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        $actor = $this->createUser($roleId, 'consulta_web');

        $this->actingAs($actor)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($actor)->get(route('admin.users.create'))->assertForbidden();
    }

    public function test_management_permission_can_create_user_and_role(): void
    {
        $roleId = $this->createRole('gestion');
        $permissionIds = [];
        foreach ([PermissionCode::UsersView, PermissionCode::UsersManage, PermissionCode::RolesView, PermissionCode::RolesManage] as $permission) {
            $permissionIds[] = $this->createPermission($permission);
        }
        DB::table('rol_permiso')->insert(array_map(static fn (int $permissionId): array => ['rol_id' => $roleId, 'permiso_id' => $permissionId], $permissionIds));
        $actor = $this->createUser($roleId, 'gestion_web');

        $response = $this->actingAs($actor)->post(route('admin.roles.store'), [
            'codigo' => 'supervisor',
            'nombre' => 'Supervisor',
            'descripcion' => 'Rol de prueba',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', ['codigo' => 'supervisor', 'nombre' => 'Supervisor']);
    }

    private function createRole(string $code): int
    {
        return (int) DB::table('roles')->insertGetId(['codigo' => $code, 'nombre' => ucfirst($code), 'descripcion' => null, 'activo' => true]);
    }

    private function createPermission(PermissionCode $permission): int
    {
        return (int) DB::table('permisos')->insertGetId(['codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(), 'descripcion' => $permission->description(), 'activo' => true]);
    }

    private function createUser(int $roleId, string $username): User
    {
        $id = (int) DB::table('usuarios')->insertGetId(['rol_id' => $roleId, 'nombre' => 'Usuario', 'apellido' => 'Web', 'nombre_usuario' => $username, 'correo' => $username.'@example.test', 'password' => Hash::make('Admin1234'), 'activo' => true, 'ultimo_acceso' => null, 'remember_token' => null]);

        return User::query()->findOrFail($id);
    }
}
