<?php

declare(strict_types=1);

namespace Tests\Feature\Recipes;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class RecipeWebTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_recipe_manager_can_create_and_version_without_rewriting_history_or_stock(): void
    {
        $actor = $this->actor([PermissionCode::RecipesView, PermissionCode::RecipesManage], 'recipe_web');
        $categoryId = $this->createArticleCategory(['codigo' => 'panes_recipe_web']);
        $unitId = $this->createUnitOfMeasure(['codigo' => 'kg_recipe_web']);
        $productId = $this->createArticle($categoryId, $unitId, ['sku' => 'pan_recipe_web', 'nombre' => 'Pan recipe web', 'tipo_articulo' => 'elaborado', 'stock_actual' => '4.000']);
        $componentId = $this->createArticle($categoryId, $unitId, ['sku' => 'harina_recipe_web', 'nombre' => 'Harina recipe web', 'tipo_articulo' => 'materia_prima', 'stock_actual' => '8.000']);

        $this->actingAs($actor)->get('/recetas')->assertOk()->assertSee('Recetas');
        $this->actingAs($actor)->post('/recetas', [
            'articulo_id' => $productId,
            'rendimiento_cantidad' => '1.000',
            'activa' => '1',
            'componentes' => [['articulo_componente_id' => $componentId, 'cantidad' => '1.000']],
        ])->assertRedirect();

        $recipeId = (int) DB::table('recetas')->where('articulo_id', $productId)->value('id');
        $this->assertDatabaseHas('detalle_recetas', ['receta_id' => $recipeId, 'articulo_componente_id' => $componentId, 'cantidad' => '1.000']);
        $this->assertSame('4.000', DB::table('articulos')->where('id', $productId)->value('stock_actual'));

        $this->actingAs($actor)->post('/recetas/'.$recipeId.'/versiones', [
            'articulo_id' => $productId,
            'rendimiento_cantidad' => '2.000',
            'activa' => '1',
            'observacion' => 'Segunda versión',
            'componentes' => [['articulo_componente_id' => $componentId, 'cantidad' => '1.500']],
        ])->assertRedirect();

        $this->assertDatabaseHas('recetas', ['id' => $recipeId, 'version' => 1, 'activa' => false, 'rendimiento_cantidad' => '1.000']);
        $this->assertDatabaseHas('recetas', ['articulo_id' => $productId, 'version' => 2, 'activa' => true, 'rendimiento_cantidad' => '2.000']);
        $this->assertSame('1.000', DB::table('detalle_recetas')->where('receta_id', $recipeId)->value('cantidad'));
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_recipe_view_permission_does_not_authorize_mutation(): void
    {
        $actor = $this->actor([PermissionCode::RecipesView], 'recipe_readonly');

        $this->actingAs($actor)->get('/recetas/crear')->assertForbidden();
        $this->actingAs($actor)->post('/recetas', [])->assertForbidden();
    }

    private function actor(array $permissions, string $suffix): User
    {
        $roleId = $this->createRole(['codigo' => 'rol_'.$suffix, 'nombre' => 'Rol '.$suffix]);
        $userId = $this->createUser($roleId, [
            'nombre_usuario' => $suffix,
            'correo' => $suffix.'@example.test',
            'password' => Hash::make('Admin1234'),
        ]);
        foreach ($permissions as $permission) {
            $permissionId = $this->createPermission([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'descripcion' => $permission->description(),
                'activo' => true,
            ]);
            $this->attachPermission($roleId, $permissionId);
        }

        return User::query()->findOrFail($userId);
    }
}
