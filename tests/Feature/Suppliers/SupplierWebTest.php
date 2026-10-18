<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SupplierWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_permission_can_read_but_cannot_mutate_suppliers(): void
    {
        $actor = $this->createActor([PermissionCode::SuppliersView], 'supplier_view');
        $supplierId = $this->createSupplier();

        $this->actingAs($actor)->get('/proveedores')->assertOk()->assertSee('Proveedor de prueba');
        $this->actingAs($actor)->get('/proveedores/'.$supplierId)->assertOk();
        $this->actingAs($actor)->post('/proveedores', [
            'codigo' => 'nuevo_proveedor',
            'nombre' => 'Nuevo proveedor',
        ])->assertForbidden();
    }

    public function test_manager_can_create_edit_associate_and_safely_detach_without_inventory_or_price_changes(): void
    {
        $actor = $this->createActor([PermissionCode::SuppliersView, PermissionCode::SuppliersManage], 'supplier_manage');
        $articleId = $this->createArticle();
        DB::table('historial_precios_venta')->insert([
            'articulo_id' => $articleId,
            'precio_anterior' => null,
            'precio_nuevo' => '1500.00',
            'vigente_desde' => now(),
            'usuario_id' => $actor->getKey(),
            'motivo' => 'Precio inicial',
            'created_at' => now(),
        ]);
        DB::table('articulos')->where('id', $articleId)->update(['precio_venta_actual' => '1500.00', 'stock_actual' => '4.250']);
        $before = DB::table('articulos')->where('id', $articleId)->first(['stock_actual', 'precio_venta_actual']);
        $beforeMovements = DB::table('movimientos_inventario')->count();
        $beforePrices = DB::table('historial_precios_venta')->count();

        $this->actingAs($actor)->post('/proveedores', [
            'codigo' => 'proveedor_web',
            'nombre' => 'Proveedor web',
            'correo' => 'web@example.test',
        ])->assertRedirect();
        $supplierId = (int) DB::table('proveedores')->where('codigo', 'proveedor_web')->value('id');

        $this->actingAs($actor)->put('/proveedores/'.$supplierId, [
            'codigo' => 'proveedor_web',
            'nombre' => 'Proveedor web actualizado',
            'nombre_contacto' => 'Contacto',
        ])->assertRedirect('/proveedores/'.$supplierId);
        $this->actingAs($actor)->post('/proveedores/'.$supplierId.'/articulos', [
            'articulo_id' => $articleId,
            'codigo_articulo_proveedor' => 'SKU-PROV-1',
        ])->assertRedirect();

        $this->assertDatabaseHas('articulo_proveedor', [
            'articulo_id' => $articleId,
            'proveedor_id' => $supplierId,
            'codigo_articulo_proveedor' => 'SKU-PROV-1',
        ]);
        $this->actingAs($actor)->get('/proveedores/'.$supplierId)->assertOk()->assertSee('Proveedor web actualizado');
        $this->actingAs($actor)->delete('/proveedores/'.$supplierId.'/articulos/'.$articleId)->assertRedirect();

        $this->assertDatabaseMissing('articulo_proveedor', ['articulo_id' => $articleId, 'proveedor_id' => $supplierId]);
        $after = DB::table('articulos')->where('id', $articleId)->first(['stock_actual', 'precio_venta_actual']);
        $this->assertSame($before->stock_actual, $after->stock_actual);
        $this->assertSame($before->precio_venta_actual, $after->precio_venta_actual);
        $this->assertSame($beforeMovements, DB::table('movimientos_inventario')->count());
        $this->assertSame($beforePrices, DB::table('historial_precios_venta')->count());
    }

    public function test_cost_view_reads_receipt_history_but_no_manual_cost_route_exists(): void
    {
        $actor = $this->createActor([PermissionCode::SuppliersView, PermissionCode::InventoryCostsView], 'supplier_cost_view');
        $supplierId = $this->createSupplier();
        $articleId = $this->createArticle();
        DB::table('historial_costos_articulo')->insert([
            'articulo_id' => $articleId,
            'proveedor_id' => $supplierId,
            'costo_anterior' => null,
            'costo_nuevo' => '1000.00',
            'vigente_desde' => now(),
            'usuario_id' => $actor->getKey(),
            'motivo' => null,
            'created_at' => now(),
            'detalle_recepcion_mercaderia_id' => null,
        ]);

        $this->actingAs($actor)->get('/proveedores/'.$supplierId)->assertOk()->assertSee('$1.000,00');
        $this->actingAs($actor)->post('/proveedores/'.$supplierId.'/costos', [
            'articulo_id' => $articleId,
            'costo_nuevo' => '1200.00',
        ])->assertNotFound();
    }

    private function createActor(array $permissions, string $suffix): User
    {
        $roleId = (int) DB::table('roles')->insertGetId(['codigo' => 'rol_'.$suffix, 'nombre' => 'Rol '.$suffix, 'descripcion' => null, 'activo' => true]);
        $userId = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId, 'nombre' => 'Usuario', 'apellido' => ucfirst($suffix), 'nombre_usuario' => $suffix,
            'correo' => $suffix.'@example.test', 'password' => Hash::make('Admin1234'), 'activo' => true,
        ]);
        foreach ($permissions as $permission) {
            $permissionId = (int) DB::table('permisos')->insertGetId([
                'codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(),
                'descripcion' => $permission->description(), 'activo' => true,
            ]);
            DB::table('rol_permiso')->insert(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        }

        return User::query()->findOrFail($userId);
    }

    private function createSupplier(): int
    {
        return (int) DB::table('proveedores')->insertGetId(['codigo' => 'proveedor_prueba', 'nombre' => 'Proveedor de prueba', 'activo' => true]);
    }

    private function createArticle(): int
    {
        $categoryId = (int) DB::table('categorias_articulo')->insertGetId(['codigo' => 'categoria_web', 'nombre' => 'Categoría web', 'activo' => true]);
        $unitId = (int) DB::table('unidades_medida')->insertGetId(['codigo' => 'unidad_web', 'nombre' => 'Unidad web', 'simbolo' => 'u', 'magnitud' => 'unidad', 'activo' => true]);

        return (int) DB::table('articulos')->insertGetId([
            'sku' => 'articulo_web', 'nombre' => 'Artículo web', 'tipo_articulo' => 'reventa',
            'categoria_articulo_id' => $categoryId, 'unidad_id' => $unitId, 'stock_actual' => '0.000',
            'stock_minimo' => '0.000', 'precio_venta_actual' => null, 'activo' => true,
        ]);
    }
}
