<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Database\Seeders\PermisosAdministracionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdministrationPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_assigns_exactly_the_six_permissions_to_existing_administrator(): void
    {
        $administrator = $this->createRole('administrador', 'Administrador');
        $otherRole = $this->createRole('operador', 'Operador');

        $this->seed(PermisosAdministracionSeeder::class);
        $this->seed(PermisosAdministracionSeeder::class);

        $codes = $this->administrationCodes();
        $permissionIds = DB::table('permisos')->whereIn('codigo', $codes)->pluck('id')->all();

        $this->assertCount(6, $permissionIds);
        $this->assertSame(6, DB::table('rol_permiso')->where('rol_id', $administrator)->whereIn('permiso_id', $permissionIds)->count());
        $this->assertSame(0, DB::table('rol_permiso')->where('rol_id', $otherRole)->whereIn('permiso_id', $permissionIds)->count());
    }

    public function test_effective_permissions_are_required_and_role_name_never_bypasses_them(): void
    {
        $roleId = $this->createRole('dueno', 'Administrador');
        $user = $this->createUser($roleId);
        $this->seed(PermisosAdministracionSeeder::class);

        $this->assertFalse(Gate::forUser($user)->allows(PermissionCode::UsersView->value));

        foreach ($this->administrationCodes() as $code) {
            $permissionId = (int) DB::table('permisos')->where('codigo', $code)->value('id');
            DB::table('rol_permiso')->insert(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        }

        $this->assertTrue(app(EffectivePermissionResolver::class)->allowsAll($user, OperationalPermissions::all()));
        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::UsersManage->value));
    }

    public function test_existing_administrator_receives_the_new_permissions_without_changing_other_roles(): void
    {
        $administrator = $this->createRole('administrador', 'Administrador');
        $operator = $this->createRole('operador', 'Operador');
        $user = $this->createUser($administrator);
        $this->seed(PermisosAdministracionSeeder::class);

        $this->assertTrue(app(EffectivePermissionResolver::class)->allowsAll($user, OperationalPermissions::all()));
        $this->assertSame(0, DB::table('rol_permiso')->where('rol_id', $operator)->count());
    }

    /** @return list<string> */
    private function administrationCodes(): array
    {
        return array_map(static fn (PermissionCode $permission): string => $permission->value, OperationalPermissions::all());
    }

    private function createRole(string $code, string $name): int
    {
        return (int) DB::table('roles')->insertGetId([
            'codigo' => $code,
            'nombre' => $name,
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUser(int $roleId): User
    {
        $id = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Admin',
            'apellido' => 'Prueba',
            'nombre_usuario' => 'admin_'.$roleId,
            'correo' => 'admin'.$roleId.'@example.test',
            'password' => Hash::make('Admin1234'),
            'activo' => true,
            'ultimo_acceso' => null,
            'remember_token' => null,
        ]);

        return User::query()->findOrFail($id);
    }
}

final class OperationalPermissions
{
    /** @return list<PermissionCode> */
    public static function all(): array
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
}
