<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use Database\Seeders\PermisosArticulosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ArticlesPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_assigns_both_permissions_to_existing_administrator(): void
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
            'nombre_usuario' => 'admin_articulos',
            'correo' => 'admin_articulos@example.test',
            'password' => Hash::make('Admin1234'),
            'activo' => true,
        ]);

        $this->seed(PermisosArticulosSeeder::class);
        $this->seed(PermisosArticulosSeeder::class);

        $this->assertSame(2, DB::table('permisos')->whereIn('codigo', [
            PermissionCode::ArticlesView->value,
            PermissionCode::ArticlesManage->value,
        ])->count());
        $this->assertSame(2, DB::table('rol_permiso')->where('rol_id', $roleId)
            ->whereIn('permiso_id', DB::table('permisos')->whereIn('codigo', [
                PermissionCode::ArticlesView->value,
                PermissionCode::ArticlesManage->value,
            ])->pluck('id'))
            ->count());

        $user = User::query()->firstOrFail();
        $this->assertTrue(app(\App\Services\Authorization\EffectivePermissionResolver::class)
            ->allows($user, PermissionCode::ArticlesView));
        $this->assertTrue(app(\App\Services\Authorization\EffectivePermissionResolver::class)
            ->allows($user, PermissionCode::ArticlesManage));
    }

    public function test_seeder_does_not_assign_article_permissions_to_other_roles(): void
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'operador',
            'nombre' => 'Operador',
            'descripcion' => null,
            'activo' => true,
        ]);

        $this->seed(PermisosArticulosSeeder::class);

        $permissionIds = DB::table('permisos')->whereIn('codigo', [
            PermissionCode::ArticlesView->value,
            PermissionCode::ArticlesManage->value,
        ])->pluck('id');

        $this->assertSame(0, DB::table('rol_permiso')->where('rol_id', $roleId)
            ->whereIn('permiso_id', $permissionIds)->count());
    }
}
