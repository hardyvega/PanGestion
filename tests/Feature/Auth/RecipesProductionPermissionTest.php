<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Database\Seeders\PermisosRecetasProduccionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RecipesProductionPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_assigns_only_the_four_new_permissions_to_administrator(): void
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
            'nombre_usuario' => 'admin_recetas',
            'correo' => 'admin_recetas@example.test',
            'password' => Hash::make('Admin1234'),
            'activo' => true,
        ]);

        $this->seed(PermisosRecetasProduccionSeeder::class);
        $this->seed(PermisosRecetasProduccionSeeder::class);

        $codes = [
            PermissionCode::RecipesView,
            PermissionCode::RecipesManage,
            PermissionCode::ProductionView,
            PermissionCode::ProductionManage,
        ];
        $ids = DB::table('permisos')->whereIn('codigo', array_map(static fn ($permission) => $permission->value, $codes))->pluck('id');

        $this->assertCount(4, $ids);
        $this->assertSame(4, DB::table('rol_permiso')->where('rol_id', $roleId)->whereIn('permiso_id', $ids)->count());

        $user = User::query()->firstOrFail();
        $resolver = app(EffectivePermissionResolver::class);
        foreach ($codes as $permission) {
            $this->assertTrue($resolver->allows($user, $permission));
        }
        $this->assertFalse($resolver->allows($user, PermissionCode::ProductionConfirm));
        $this->assertFalse($resolver->allows($user, PermissionCode::ProductionAnnul));
    }
}
