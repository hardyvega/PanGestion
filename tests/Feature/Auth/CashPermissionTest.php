<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use Database\Seeders\PermisosCajaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class CashPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_seeder_is_idempotent_and_assigns_only_to_existing_administrator(): void
    {
        DB::table('roles')->insert([
            'codigo' => 'administrador',
            'nombre' => 'Administrador',
            'descripcion' => null,
            'activo' => true,
        ]);

        $this->seed(PermisosCajaSeeder::class);
        $first = DB::table('permisos')->where('modulo', 'caja')->orderBy('codigo')->pluck('id', 'codigo')->all();

        $this->seed(PermisosCajaSeeder::class);
        $second = DB::table('permisos')->where('modulo', 'caja')->orderBy('codigo')->pluck('id', 'codigo')->all();

        $this->assertSame($first, $second);
        $this->assertCount(4, $second);
        $this->assertSame(4, DB::table('rol_permiso')->count());
    }

    public function test_effective_permissions_are_independent(): void
    {
        $roleId = $this->createRole();
        $user = $this->createUser($roleId);
        $permissionIds = [];

        foreach ([PermissionCode::CashSessionsView, PermissionCode::CashSessionsOpen] as $permission) {
            $permissionIds[] = (int) DB::table('permisos')->insertGetId([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'descripcion' => $permission->description(),
                'activo' => true,
            ]);
        }

        DB::table('rol_permiso')->insert([
            ['rol_id' => $roleId, 'permiso_id' => $permissionIds[0]],
            ['rol_id' => $roleId, 'permiso_id' => $permissionIds[1]],
        ]);

        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::CashSessionsView->value));
        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::CashSessionsOpen->value));
        $this->assertFalse(Gate::forUser($user)->allows(PermissionCode::CashSessionsClose->value));
        $this->assertFalse(Gate::forUser($user)->allows(PermissionCode::CashMovementsView->value));
    }

    private function createRole(): int
    {
        return (int) DB::table('roles')->insertGetId([
            'codigo' => 'operador_caja',
            'nombre' => 'Operador de caja',
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUser(int $roleId): \App\Models\User
    {
        $id = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Usuario',
            'apellido' => 'Caja',
            'nombre_usuario' => 'usuario_caja',
            'correo' => 'caja@example.test',
            'password' => 'hash',
            'activo' => true,
        ]);

        return \App\Models\User::query()->findOrFail($id);
    }
}
