<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Database\Seeders\PermisosProveedoresSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SuppliersPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_assigns_exactly_the_three_permissions_to_existing_administrator(): void
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'administrador',
            'nombre' => 'Administrador',
            'descripcion' => null,
            'activo' => true,
        ]);
        DB::table('usuarios')->insert([
            'rol_id' => $roleId,
            'nombre' => 'Admin',
            'apellido' => 'Inicial',
            'nombre_usuario' => 'admin_proveedores',
            'correo' => 'admin_proveedores@example.test',
            'password' => Hash::make('Admin1234'),
            'activo' => true,
        ]);

        $this->seed(PermisosProveedoresSeeder::class);
        $this->seed(PermisosProveedoresSeeder::class);

        $codes = [
            PermissionCode::SuppliersView->value,
            PermissionCode::SuppliersManage->value,
            PermissionCode::InventoryCostsView->value,
        ];
        $permissionIds = DB::table('permisos')->whereIn('codigo', $codes)->pluck('id');

        $this->assertSame(3, DB::table('permisos')->whereIn('codigo', $codes)->count());
        $this->assertSame(3, DB::table('rol_permiso')->where('rol_id', $roleId)->whereIn('permiso_id', $permissionIds)->count());

        $user = User::query()->firstOrFail();
        $resolver = app(EffectivePermissionResolver::class);
        $this->assertTrue($resolver->allows($user, PermissionCode::SuppliersView));
        $this->assertTrue($resolver->allows($user, PermissionCode::SuppliersManage));
        $this->assertTrue($resolver->allows($user, PermissionCode::InventoryCostsView));
        $this->assertFalse($resolver->allows($user, PermissionCode::InventoryCostsRegister));
    }

    public function test_seeder_does_not_assign_supplier_permissions_to_other_roles(): void
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'operador',
            'nombre' => 'Operador',
            'descripcion' => null,
            'activo' => true,
        ]);

        $this->seed(PermisosProveedoresSeeder::class);

        $permissionIds = DB::table('permisos')->whereIn('codigo', [
            PermissionCode::SuppliersView->value,
            PermissionCode::SuppliersManage->value,
            PermissionCode::InventoryCostsView->value,
        ])->pluck('id');

        $this->assertSame(0, DB::table('rol_permiso')->where('rol_id', $roleId)->whereIn('permiso_id', $permissionIds)->count());
    }
}
