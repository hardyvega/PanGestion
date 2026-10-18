<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Administration;

use App\Enums\PermissionCode;
use App\Exceptions\OperationalAdminException;
use App\Models\Rol;
use App\Models\User;
use App\Services\Administration\RoleAdministrationService;
use App\Services\Administration\UserAdministrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdministrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_deactivate_themselves(): void
    {
        [$roleId, $actor] = $this->createOperationalAdmin('admin_self');

        $this->expectException(OperationalAdminException::class);
        app(UserAdministrationService::class)->setActive($actor, $actor, false);

        $this->assertDatabaseHas('usuarios', ['id' => $actor->getKey(), 'activo' => true]);
    }

    public function test_last_operational_admin_cannot_be_deactivated_or_have_role_changed(): void
    {
        [$roleId, $actor] = $this->createOperationalAdmin('admin_last');
        $otherRoleId = $this->createRole('operador');

        try {
            app(UserAdministrationService::class)->setActive($actor, $actor, false);
            $this->fail('Expected self deactivation to be rejected.');
        } catch (OperationalAdminException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(UserAdministrationService::class)->update($actor, $actor, [
                'nombre' => $actor->nombre,
                'apellido' => $actor->apellido,
                'nombre_usuario' => $actor->nombre_usuario,
                'correo' => $actor->correo,
                'rol_id' => $otherRoleId,
                'activo' => true,
            ]);
            $this->fail('Expected last administrator role change to be rejected.');
        } catch (OperationalAdminException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('usuarios', ['id' => $actor->getKey(), 'rol_id' => $roleId, 'activo' => true]);
    }

    public function test_removing_permissions_from_the_last_operational_role_is_rejected(): void
    {
        [$roleId, $actor] = $this->createOperationalAdmin('admin_permissions');

        $this->expectException(OperationalAdminException::class);
        app(RoleAdministrationService::class)->syncPermissions($actor, Rol::query()->findOrFail($roleId), []);
    }

    public function test_administrative_permissions_can_be_removed_when_another_operational_admin_remains(): void
    {
        [$roleId, $first] = $this->createOperationalAdmin('admin_first');
        [$secondRoleId, $second] = $this->createOperationalAdmin('admin_second');
        $role = Rol::query()->findOrFail($roleId);
        $permissionIds = DB::table('permisos')->whereIn('codigo', $this->codes())->pluck('id')->all();

        app(RoleAdministrationService::class)->syncPermissions($second, $role, array_slice($permissionIds, 0, 5));

        $this->assertSame(5, DB::table('rol_permiso')->where('rol_id', $roleId)->count());
        $this->assertSame(6, DB::table('rol_permiso')->where('rol_id', $secondRoleId)->count());
        $this->assertTrue($second->refresh()->activo);
    }

    public function test_operational_status_uses_effective_permissions_and_not_role_name(): void
    {
        $roleId = $this->createRole('dueno', 'Administrador');
        $user = $this->createUser($roleId, 'named_admin');

        $this->assertFalse(app(\App\Services\Authorization\EffectivePermissionResolver::class)->allows($user, PermissionCode::UsersView));
    }

    /** @return array{0: int, 1: User} */
    private function createOperationalAdmin(string $username): array
    {
        $roleId = $this->createRole('operativo_'.str_replace('admin_', '', $username));
        $permissionIds = [];
        foreach ($this->codes() as $code) {
            $permissionIds[] = (int) (DB::table('permisos')->where('codigo', $code)->value('id') ?? DB::table('permisos')->insertGetId([
                'codigo' => $code,
                'nombre' => $code,
                'modulo' => explode('.', $code, 2)[0],
                'descripcion' => $code,
                'activo' => true,
            ]));
        }
        DB::table('rol_permiso')->insert(array_map(static fn (int $permissionId): array => ['rol_id' => $roleId, 'permiso_id' => $permissionId], $permissionIds));

        return [$roleId, $this->createUser($roleId, $username)];
    }

    private function createRole(string $code): int
    {
        return (int) DB::table('roles')->insertGetId(['codigo' => $code, 'nombre' => ucfirst($code), 'descripcion' => null, 'activo' => true]);
    }

    private function createUser(int $roleId, string $username): User
    {
        $id = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Usuario',
            'apellido' => 'Admin',
            'nombre_usuario' => $username,
            'correo' => $username.'@example.test',
            'password' => Hash::make('Admin1234'),
            'activo' => true,
            'ultimo_acceso' => null,
            'remember_token' => null,
        ]);

        return User::query()->findOrFail($id);
    }

    /** @return list<string> */
    private function codes(): array
    {
        return [
            PermissionCode::UsersView->value,
            PermissionCode::UsersManage->value,
            PermissionCode::RolesView->value,
            PermissionCode::RolesManage->value,
            PermissionCode::PermissionsView->value,
            PermissionCode::RolePermissionsManage->value,
        ];
    }
}
