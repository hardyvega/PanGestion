<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class ProductionWebTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_starting_then_confirming_production_uses_the_existing_service_and_ledger(): void
    {
        $actor = $this->actor([
            PermissionCode::ProductionView,
            PermissionCode::ProductionManage,
            PermissionCode::ProductionConfirm,
        ], 'production_web');
        $categoryId = $this->createArticleCategory(['codigo' => 'panes_production_web']);
        $unitId = $this->createUnitOfMeasure(['codigo' => 'kg_production_web']);
        $productId = $this->createArticle($categoryId, $unitId, ['sku' => 'pan_production_web', 'nombre' => 'Pan production web', 'tipo_articulo' => 'elaborado']);
        $componentId = $this->createArticle($categoryId, $unitId, ['sku' => 'harina_production_web', 'nombre' => 'Harina production web', 'tipo_articulo' => 'materia_prima', 'stock_actual' => '5.000']);
        $this->createInventoryMovement($componentId, $actor->getKey(), InventoryMovementType::InitialBalance->value, 'f7000000-0000-4000-8000-000000000001', [
            'usuario_autorizador_id' => $actor->getKey(),
            'cantidad_delta' => '5.000',
            'stock_anterior' => '0.000',
            'stock_posterior' => '5.000',
            'motivo' => 'Saldo inicial de prueba',
        ]);
        $recipeId = $this->createRecipe($productId, ['activa' => true, 'rendimiento_cantidad' => '1.000']);
        $recipeDetailId = $this->createRecipeDetail($recipeId, $componentId, ['cantidad' => '1.000']);

        $this->actingAs($actor)->post('/producciones', [
            'receta_id' => $recipeId,
            'cantidad_producida' => '1.000',
            'consumos' => [$recipeDetailId => '1.000'],
        ])->assertRedirect();

        $productionId = (int) DB::table('producciones')->max('id');
        $this->assertSame('borrador', DB::table('producciones')->where('id', $productionId)->value('estado'));
        $this->assertSame('5.000', DB::table('articulos')->where('id', $componentId)->value('stock_actual'));
        $this->assertSame('0.000', DB::table('articulos')->where('id', $productId)->value('stock_actual'));

        $this->actingAs($actor)->post('/producciones/'.$productionId.'/confirmar', [
            'command_uuid' => 'f7000000-0000-4000-8000-000000000002',
        ])->assertRedirect();

        $this->assertSame('confirmada', DB::table('producciones')->where('id', $productionId)->value('estado'));
        $this->assertSame('4.000', DB::table('articulos')->where('id', $componentId)->value('stock_actual'));
        $this->assertSame('1.000', DB::table('articulos')->where('id', $productId)->value('stock_actual'));
        $this->assertDatabaseHas('movimientos_inventario', ['consumo_produccion_id' => DB::table('consumos_produccion')->where('produccion_id', $productionId)->value('id'), 'cantidad_delta' => '-1.000']);
    }

    private function actor(array $permissions, string $suffix): User
    {
        $roleId = $this->createRole(['codigo' => 'rol_'.$suffix, 'nombre' => 'Rol '.$suffix]);
        $userId = $this->createUser($roleId, ['nombre_usuario' => $suffix, 'correo' => $suffix.'@example.test', 'password' => Hash::make('Admin1234')]);
        foreach ($permissions as $permission) {
            $permissionId = $this->createPermission(['codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(), 'descripcion' => $permission->description(), 'activo' => true]);
            $this->attachPermission($roleId, $permissionId);
        }

        return User::query()->findOrFail($userId);
    }
}
