<?php

declare(strict_types=1);

namespace Tests\Feature\Articles;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ArticleWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_routes_require_authentication_and_effective_permissions(): void
    {
        $this->get('/articulos')->assertRedirect('/login');
        $viewer = $this->createActor([PermissionCode::ArticlesView], 'article_viewer');
        $manager = $this->createActor([PermissionCode::ArticlesManage], 'article_manager');

        $this->actingAs($viewer)->get('/articulos/crear')->assertForbidden();
        $this->actingAs($manager)->get('/articulos')->assertForbidden();
    }

    public function test_index_searches_by_name_sku_barcode_and_filters_low_stock(): void
    {
        $actor = $this->createActor([PermissionCode::ArticlesView], 'article_index');
        $category = $this->createCategory('panes_index');
        $unit = $this->createUnit('unidad_index');
        $target = $this->createArticle($category, $unit, [
            'sku' => 'marraqueta_index',
            'nombre' => 'Marraqueta index',
            'codigo_barras' => '000123456',
            'stock_actual' => '2.000',
            'stock_minimo' => '5.000',
        ]);
        $this->createArticle($category, $unit, [
            'sku' => 'otro_index',
            'nombre' => 'Otro artículo',
            'stock_actual' => '20.000',
            'stock_minimo' => '1.000',
        ]);

        $this->actingAs($actor)->get('/articulos?q=000123456&stock_bajo=1')
            ->assertOk()
            ->assertSee('Marraqueta index')
            ->assertSee('000123456')
            ->assertDontSee('Otro artículo');
        $this->assertDatabaseHas('articulos', ['id' => $target]);
    }

    public function test_manager_can_create_article_with_initial_price_history_without_writing_stock(): void
    {
        $actor = $this->createActor([PermissionCode::ArticlesManage], 'article_create');
        $category = $this->createCategory('panes_create');
        $unit = $this->createUnit('unidad_create');

        $response = $this->actingAs($actor)->post('/articulos', [
            'sku' => 'pan_create',
            'nombre' => 'Pan creado',
            'descripcion' => 'Descripción de prueba',
            'tipo_articulo' => 'elaborado',
            'categoria_articulo_id' => $category,
            'unidad_id' => $unit,
            'codigo_barras' => '00000999',
            'stock_minimo' => '3.500',
            'precio_venta' => '1500.00',
            'motivo_precio' => 'Precio inicial',
            'stock_actual' => '999.000',
        ]);

        $articleId = (int) DB::table('articulos')->where('sku', 'pan_create')->value('id');
        $response->assertRedirect(route('articles.show', $articleId));
        $this->assertDatabaseHas('articulos', [
            'id' => $articleId,
            'codigo_barras' => '00000999',
            'stock_actual' => '0.000',
            'stock_minimo' => '3.500',
            'precio_venta_actual' => '1500.00',
        ]);
        $this->assertDatabaseHas('historial_precios_venta', [
            'articulo_id' => $articleId,
            'precio_anterior' => null,
            'precio_nuevo' => '1500.00',
            'usuario_id' => $actor->getKey(),
        ]);
        $this->assertDatabaseCount('articulos', 1);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_update_changes_master_data_and_price_history_but_never_stock_or_inventory_movements(): void
    {
        Storage::fake('public');
        $actor = $this->createActor([PermissionCode::ArticlesManage], 'article_update');
        $category = $this->createCategory('panes_update');
        $unit = $this->createUnit('unidad_update');
        $articleId = $this->createArticle($category, $unit, [
            'sku' => 'pan_update',
            'nombre' => 'Pan antiguo',
            'codigo_barras' => '00000011',
            'stock_actual' => '7.250',
            'stock_minimo' => '1.000',
            'precio_venta_actual' => '1000.00',
        ]);

        $response = $this->actingAs($actor)->put('/articulos/'.$articleId, [
            'sku' => 'pan_update_new',
            'nombre' => 'Pan actualizado',
            'tipo_articulo' => 'reventa',
            'categoria_articulo_id' => $category,
            'unidad_id' => $unit,
            'codigo_barras' => '00000012',
            'stock_minimo' => '2.000',
            'precio_venta' => '1200.00',
            'motivo_precio' => 'Ajuste comercial',
            'stock_actual' => '0.000',
            'imagen' => UploadedFile::fake()->create('pan.webp', 100, 'image/webp'),
        ]);

        $response->assertRedirect(route('articles.show', $articleId));
        $this->assertSame('7.250', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
        $this->assertSame('00000012', DB::table('articulos')->where('id', $articleId)->value('codigo_barras'));
        $this->assertDatabaseCount('articulos', 1);
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertDatabaseHas('historial_precios_venta', [
            'articulo_id' => $articleId,
            'precio_anterior' => '1000.00',
            'precio_nuevo' => '1200.00',
        ]);
        $path = DB::table('articulos')->where('id', $articleId)->value('imagen_ruta');
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_price_route_is_append_only_and_deactivation_removes_article_from_sellable_lookup(): void
    {
        $actor = $this->createActor([PermissionCode::ArticlesManage], 'article_price');
        $category = $this->createCategory('panes_price');
        $unit = $this->createUnit('unidad_price');
        $articleId = $this->createArticle($category, $unit, [
            'sku' => 'pan_price',
            'nombre' => 'Pan con precio',
            'precio_venta_actual' => '2000.00',
        ]);

        $this->actingAs($actor)->post('/articulos/'.$articleId.'/precios', [
            'precio_venta' => '2100.00',
            'motivo_precio' => 'Actualización',
        ])->assertRedirect(route('articles.show', $articleId));
        $this->assertSame(1, DB::table('historial_precios_venta')->where('articulo_id', $articleId)->count());

        $this->actingAs($actor)->post('/articulos/'.$articleId.'/desactivar')->assertRedirect();
        $this->assertFalse((bool) DB::table('articulos')->where('id', $articleId)->value('activo'));
        $this->assertSame(0, app(\App\Services\Articles\SellableArticleLookupService::class)->search('pan_price')->count());
    }

    public function test_svg_images_are_rejected(): void
    {
        $actor = $this->createActor([PermissionCode::ArticlesManage], 'article_svg');
        $category = $this->createCategory('panes_svg');
        $unit = $this->createUnit('unidad_svg');

        $this->actingAs($actor)->from('/articulos/crear')->post('/articulos', [
            'sku' => 'pan_svg',
            'nombre' => 'Pan SVG',
            'tipo_articulo' => 'elaborado',
            'categoria_articulo_id' => $category,
            'unidad_id' => $unit,
            'stock_minimo' => '0.000',
            'imagen' => UploadedFile::fake()->create('pan.svg', 10, 'image/svg+xml'),
        ])->assertRedirect('/articulos/crear')->assertSessionHasErrors('imagen');
    }

    public function test_both_article_forms_enable_the_scoped_scanner_guard_and_keep_native_validation(): void
    {
        $actor = $this->createActor([PermissionCode::ArticlesManage], 'article_scanner');
        $category = $this->createCategory('scanner_category');
        $unit = $this->createUnit('scanner_unit');
        $articleId = $this->createArticle($category, $unit, ['codigo_barras' => '0001234567890']);

        foreach (['/articulos/crear' => 'Crear artículo', '/articulos/'.$articleId.'/editar' => 'Guardar cambios'] as $url => $label) {
            $this->actingAs($actor)->get($url)->assertOk()
                ->assertSee('enctype="multipart/form-data" data-article-form', false)
                ->assertSee('type="text" name="codigo_barras"', false)
                ->assertSee('name="_token"', false)
                ->assertSee('maxlength="50" required', false)
                ->assertSee('type="submit">'.$label, false)
                ->assertDontSee('novalidate', false);
        }

        $this->get('/articulos/'.$articleId.'/editar')->assertSee('value="0001234567890"', false);
        $this->assertDatabaseCount('articulos', 1);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_validation_preserves_scanned_text_and_does_not_create_or_update_articles(): void
    {
        $actor = $this->createActor([PermissionCode::ArticlesManage], 'article_scan_invalid');
        $category = $this->createCategory('scan_invalid_category');
        $unit = $this->createUnit('scan_invalid_unit');
        $articleId = $this->createArticle($category, $unit, ['codigo_barras' => 'original-barcode']);
        $payload = [
            'sku' => 'scan_invalid', 'nombre' => '', 'tipo_articulo' => 'reventa',
            'categoria_articulo_id' => $category, 'unidad_id' => $unit,
            'codigo_barras' => '0007501013118056', 'stock_minimo' => '0.000',
        ];

        $this->actingAs($actor)->from('/articulos/crear')->post('/articulos', $payload)
            ->assertRedirect('/articulos/crear')->assertSessionHasErrors('nombre')
            ->assertSessionHasInput('codigo_barras', '0007501013118056');
        $this->get('/articulos/crear')->assertSee('value="0007501013118056"', false);

        $edit = '/articulos/'.$articleId.'/editar';
        $this->from($edit)->put('/articulos/'.$articleId, $payload)
            ->assertRedirect($edit)->assertSessionHasErrors('nombre')
            ->assertSessionHasInput('codigo_barras', '0007501013118056');
        $this->get($edit)->assertSee('value="0007501013118056"', false);
        $this->assertDatabaseCount('articulos', 1);
        $this->assertDatabaseHas('articulos', ['id' => $articleId, 'codigo_barras' => 'original-barcode']);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('historial_precios_venta', 0);
    }

    private function createActor(array $permissions, string $suffix): User
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'rol_'.$suffix,
            'nombre' => 'Rol '.$suffix,
            'descripcion' => null,
            'activo' => true,
        ]);
        $userId = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Usuario',
            'apellido' => ucfirst($suffix),
            'nombre_usuario' => $suffix,
            'correo' => $suffix.'@example.test',
            'password' => Hash::make('Admin1234'),
            'activo' => true,
        ]);

        foreach ($permissions as $permission) {
            $permissionId = (int) DB::table('permisos')->insertGetId([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'descripcion' => $permission->description(),
                'activo' => true,
            ]);
            DB::table('rol_permiso')->insert(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
        }

        return User::query()->findOrFail($userId);
    }

    private function createCategory(string $suffix): int
    {
        return (int) DB::table('categorias_articulo')->insertGetId([
            'codigo' => $suffix,
            'nombre' => ucfirst($suffix),
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUnit(string $suffix): int
    {
        return (int) DB::table('unidades_medida')->insertGetId([
            'codigo' => $suffix,
            'nombre' => ucfirst($suffix),
            'simbolo' => 'u'.$suffix,
            'magnitud' => 'unidad',
            'activo' => true,
        ]);
    }

    private function createArticle(int $categoryId, int $unitId, array $overrides = []): int
    {
        return (int) DB::table('articulos')->insertGetId(array_merge([
            'sku' => 'pan_prueba',
            'nombre' => 'Pan de prueba',
            'tipo_articulo' => 'elaborado',
            'categoria_articulo_id' => $categoryId,
            'unidad_id' => $unitId,
            'stock_actual' => '0.000',
            'stock_minimo' => '0.000',
            'activo' => true,
        ], $overrides));
    }
}
