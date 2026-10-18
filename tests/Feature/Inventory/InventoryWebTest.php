<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\PermissionCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class InventoryWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_and_ledger_are_readable_only_with_effective_permissions(): void
    {
        $viewer = $this->createActor([PermissionCode::ArticlesView, PermissionCode::InventoryMovementsView], 'inventory_view');
        $articleId = $this->createArticle('stock_web', 'Harina web', '2.000', '5.000');
        $this->actingAs($viewer)->get('/inventario?q=stock_web&stock_bajo=1')->assertOk()->assertSee('Harina web')->assertSee('Stock bajo');
        $this->actingAs($viewer)->get('/inventario/articulos/'.$articleId)->assertOk()->assertSee('Detalle de stock');
        $this->actingAs($viewer)->get('/inventario/movimientos')->assertOk();
        $withoutStock = $this->createActor([PermissionCode::InventoryMovementsView], 'inventory_no_stock');
        $this->actingAs($withoutStock)->get('/inventario')->assertForbidden();
    }

    public function test_receipt_web_flow_delegates_stock_ledger_and_cost_to_receipt_service(): void
    {
        $actor = $this->createActor([
            PermissionCode::ArticlesView,
            PermissionCode::InventoryReceiptsView,
            PermissionCode::InventoryReceiptsCreate,
            PermissionCode::InventoryReceiptsEdit,
            PermissionCode::InventoryReceiptsConfirm,
            PermissionCode::InventoryCostsRegister,
        ], 'receipt_web');
        $articleId = $this->createArticle('receipt_web', 'Artículo recibido');
        $supplierId = (int) DB::table('proveedores')->insertGetId(['codigo' => 'supplier_web', 'nombre' => 'Proveedor web', 'activo' => true]);

        $this->actingAs($actor)->post('/recepciones', [
            'tipo_origen' => 'proveedor', 'proveedor_id' => $supplierId, 'observacion' => 'Recepción web',
        ])->assertRedirect();
        $receiptId = (int) DB::table('recepciones_mercaderia')->max('id');
        $this->actingAs($actor)->get('/recepciones/'.$receiptId)->assertOk()->assertSee('Recepción #'.$receiptId);
        $this->actingAs($actor)->post('/recepciones/'.$receiptId.'/lineas', [
            'articulo_id' => $articleId, 'cantidad' => '2.500', 'costo_unitario' => '850.00',
        ])->assertRedirect();
        $this->actingAs($actor)->post('/recepciones/'.$receiptId.'/confirmar', [
            'command_uuid' => 'f8100000-0000-4000-8000-000000000001',
        ])->assertRedirect();

        $this->assertSame('2.500', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
        $this->assertSame(1, DB::table('movimientos_inventario')->where('tipo_movimiento', 'recepcion')->count());
        $this->assertDatabaseHas('historial_costos_articulo', ['articulo_id' => $articleId, 'costo_nuevo' => '850.00']);
        $this->assertDatabaseHas('recepciones_mercaderia', ['id' => $receiptId, 'estado' => 'confirmada']);
    }

    public function test_adjustment_count_and_waste_web_flows_use_services_and_keep_decimal_precision(): void
    {
        $actor = $this->createActor([
            PermissionCode::ArticlesView,
            PermissionCode::InventoryAdjustmentsInput,
            PermissionCode::InventoryAdjustmentsOutput,
            PermissionCode::InventoryCountsManage,
            PermissionCode::InventoryCountsConfirm,
            PermissionCode::InventoryWasteRegister,
            PermissionCode::InventoryMovementsView,
        ], 'inventory_operations');
        $articleId = $this->createArticle('operations_web', 'Artículo operacional', '0.000', '1.000');

        $this->actingAs($actor)->post('/inventario/ajustes', [
            'tipo' => 'entrada', 'articulo_id' => $articleId, 'cantidad' => '5.000', 'motivo' => 'Saldo inicial operacional',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)->post('/inventario/ajustes', [
            'tipo' => 'salida', 'articulo_id' => $articleId, 'cantidad' => '1.250', 'motivo' => 'Corrección operacional',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('3.750', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));

        $this->actingAs($actor)->post('/inventario/mermas', [
            'articulo_id' => $articleId, 'cantidad' => '0.500', 'motivo' => 'Producto dañado',
        ])->assertRedirect();
        $this->assertSame('3.250', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));

        $this->actingAs($actor)->post('/inventario/conteos', [
            'articulos' => [['articulo_id' => $articleId, 'cantidad_contada' => '2.750']],
            'observacion' => 'Conteo de cierre',
        ])->assertRedirect();
        $countId = (int) DB::table('conteos_inventario')->max('id');
        $this->actingAs($actor)->post('/inventario/conteos/'.$countId.'/confirmar')->assertRedirect();

        $this->assertSame('2.750', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
        $this->assertSame(4, DB::table('movimientos_inventario')->count());
        $this->assertDatabaseHas('movimientos_inventario', ['tipo_movimiento' => 'ajuste_conteo_negativo', 'cantidad_delta' => '-0.500']);
    }

    private function createActor(array $permissions, string $suffix): object
    {
        $roleId = (int) DB::table('roles')->insertGetId(['codigo' => 'rol_'.$suffix, 'nombre' => 'Rol '.$suffix, 'descripcion' => null, 'activo' => true]);
        $userId = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId, 'nombre' => 'Usuario', 'apellido' => $suffix, 'nombre_usuario' => $suffix,
            'correo' => $suffix.'@example.test', 'password' => Hash::make('Admin1234'), 'activo' => true,
        ]);
        foreach ($permissions as $permission) {
            $permissionId = (int) (DB::table('permisos')->where('codigo', $permission->value)->value('id')
                ?? DB::table('permisos')->insertGetId([
                    'codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(),
                    'descripcion' => $permission->description(), 'activo' => true,
                ]));
            DB::table('rol_permiso')->insert(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        }

        return \App\Models\User::query()->findOrFail($userId);
    }

    private function createArticle(string $sku, string $name, string $stock = '0.000', string $minimum = '0.000'): int
    {
        $categoryId = (int) DB::table('categorias_articulo')->insertGetId(['codigo' => 'cat_'.$sku, 'nombre' => 'Categoría '.$sku, 'activo' => true]);
        $unitId = (int) DB::table('unidades_medida')->insertGetId(['codigo' => 'unidad_'.$sku, 'nombre' => 'Unidad '.$sku, 'simbolo' => 'u', 'magnitud' => 'unidad', 'activo' => true]);
        return (int) DB::table('articulos')->insertGetId([
            'sku' => $sku, 'nombre' => $name, 'tipo_articulo' => 'materia_prima', 'categoria_articulo_id' => $categoryId,
            'unidad_id' => $unitId, 'stock_actual' => $stock, 'stock_minimo' => $minimum, 'activo' => true,
        ]);
    }
}
