<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\PermissionCode;
use Carbon\CarbonImmutable;
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

    public function test_stock_and_catalog_present_chilean_quantities_without_changing_stored_values(): void
    {
        $viewer = $this->createActor([PermissionCode::ArticlesView], 'formatted_stock');
        $examples = [
            ['sprite', 'Sprite de prueba', '11.000', '4.000', 'un', '11 un', '4 un'],
            ['jumex', 'Jumex de prueba', '12.000', '1.000', 'un', '12 un', '1 un'],
            ['harina', 'Harina de prueba', '2.500', '0.125', 'kg', '2,5 kg', '0,125 kg'],
            ['aceite', 'Aceite de prueba', '0.035', '0.000', 'L', '0,035 L', '0 L'],
            ['envases', 'Envases de prueba', '1234.000', '4.000', 'un', '1.234 un', '4 un'],
        ];

        $units = [];
        foreach (['un' => 'unidad', 'kg' => 'masa', 'L' => 'volumen'] as $symbol => $magnitude) {
            $units[$symbol] = (int) DB::table('unidades_medida')->insertGetId([
                'codigo' => 'cantidad_'.strtolower($symbol), 'nombre' => 'Unidad de prueba '.$symbol,
                'simbolo' => $symbol, 'magnitud' => $magnitude, 'activo' => true,
            ]);
        }

        foreach ($examples as [$sku, $name, $stock, $minimum, $unit, $stockLabel, $minimumLabel]) {
            $id = $this->createArticle($sku, $name, $stock, $minimum, $units[$unit]);

            foreach ([
                route('inventory.index', ['q' => $sku]),
                route('inventory.show', $id),
                route('articles.index', ['q' => $sku]),
                route('articles.show', $id),
            ] as $url) {
                $this->actingAs($viewer)->get($url)->assertOk()
                    ->assertSee($stockLabel)->assertSee($minimumLabel)
                    ->assertDontSee($stock)->assertDontSee($minimum);
            }
            $this->assertDatabaseHas('articulos', ['id' => $id, 'stock_actual' => $stock, 'stock_minimo' => $minimum]);
        }
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
            PermissionCode::InventoryMovementsView,
        ], 'receipt_web');
        $articleId = $this->createArticle('receipt_web', 'Artículo recibido');
        $unitId = DB::table('articulos')->where('id', $articleId)->value('unidad_id');
        DB::table('unidades_medida')->where('id', $unitId)->update(['simbolo' => 'kg', 'magnitud' => 'masa']);
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
        $movement = DB::table('movimientos_inventario')->where('articulo_id', $articleId)->first();
        $this->get(route('inventory.movements', ['articulo_id' => $articleId]))
            ->assertOk()->assertSeeInOrder(['Artículo recibido', '+2,5 kg', '2,5 kg', 'Recepción #'.$movement->detalle_recepcion_mercaderia_id]);
        $this->get(route('inventory.show', $articleId))->assertOk()
            ->assertSeeInOrder(['Ledger causal', '+2,5 kg', '2,5 kg', 'Recepciones', '2,5 kg · costo 850.00', 'Stock diario']);
        $this->assertEquals($movement, DB::table('movimientos_inventario')->where('id', $movement->id)->first());
    }

    public function test_movement_history_formats_units_and_preserves_original_amounts_and_references(): void
    {
        $actor = $this->createActor([
            PermissionCode::InventoryMovementsView,
            PermissionCode::InventoryAdjustmentsInput,
            PermissionCode::InventoryAdjustmentsOutput,
        ], 'movement_display');
        $articleId = $this->createArticle('movement_display', 'Bebida de prueba');
        $unitId = DB::table('articulos')->where('id', $articleId)->value('unidad_id');
        DB::table('unidades_medida')->where('id', $unitId)->update(['simbolo' => 'un']);

        foreach (['entrada' => '12', 'salida' => '3'] as $type => $quantity) {
            $this->actingAs($actor)->post(route('inventory.adjustments.store'), [
                'articulo_id' => $articleId, 'tipo' => $type, 'cantidad' => $quantity, 'motivo' => 'Verificación de formato',
            ])->assertRedirect(route('inventory.movements'))->assertSessionHasNoErrors();
        }

        $rows = DB::table('movimientos_inventario')->where('articulo_id', $articleId)->orderBy('id');
        $original = $rows->get();
        $this->assertSame(['12.000', '-3.000'], $original->pluck('cantidad_delta')->all());
        $this->assertSame(['12.000', '9.000'], $original->pluck('stock_posterior')->all());
        DB::table('unidades_medida')->where('id', $unitId)->update(['simbolo' => 'ud']);

        $this->get(route('inventory.movements', ['articulo_id' => $articleId]))
            ->assertOk()
            ->assertSee('Cambio de stock')->assertSee('Stock resultante')
            ->assertSee('data-label="Cambio de stock"', false)->assertSee('data-label="Stock resultante"', false)
            ->assertDontSee('12.000')->assertDontSee('9.000')
            ->assertSeeInOrder(['Bebida de prueba', 'ajuste manual salida', '−3 un', '9 un', 'Ajuste / otro',
                'Bebida de prueba', 'ajuste manual entrada', '+12 un', '12 un', 'Ajuste / otro']);

        $this->assertEquals($original, $rows->get());
        $this->assertDatabaseHas('articulos', ['id' => $articleId, 'stock_actual' => '9.000']);
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

    public function test_adjustment_picker_uses_the_same_permissions_and_only_lists_active_articles(): void
    {
        $this->get(route('inventory.adjustments.create'))->assertRedirect(route('login'));
        $denied = $this->createActor([PermissionCode::ArticlesView], 'picker_denied');
        $this->actingAs($denied)->get(route('inventory.adjustments.create'))->assertForbidden();

        $id = $this->createArticle('picker_active', 'Bebida de prueba', '7.500');
        DB::table('articulos')->where('id', $id)->update(['codigo_barras' => '0000123456789']);
        $classification = DB::table('articulos')->where('id', $id)->first(['categoria_articulo_id', 'unidad_id']);
        DB::table('articulos')->insert([
            ...(array) $classification, 'sku' => 'picker_inactive', 'nombre' => 'Bebida inactiva',
            'tipo_articulo' => 'materia_prima', 'stock_actual' => '0.000', 'stock_minimo' => '0.000', 'activo' => false,
        ]);

        foreach ([PermissionCode::InventoryAdjustmentsInput, PermissionCode::InventoryAdjustmentsOutput] as $index => $permission) {
            $actor = $this->createActor([$permission], 'picker_allowed_'.$index);
            $this->actingAs($actor)->get(route('inventory.adjustments.create'))->assertOk()
                ->assertSee('data-adjustment-form', false)
                ->assertSee('name="articulo_id"', false)
                ->assertSee('data-barcode="0000123456789"', false)
                ->assertSee('data-stock="7.500"', false)
                ->assertSee('data-unit="u"', false)
                ->assertDontSee('picker_inactive');
        }
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_adjustment_requires_a_real_article_id_and_keeps_the_previous_selection_after_an_error(): void
    {
        $actor = $this->createActor([PermissionCode::InventoryAdjustmentsInput], 'picker_form');
        $id = $this->createArticle('picker_form', 'Artículo seleccionado');
        $create = route('inventory.adjustments.create');
        $store = route('inventory.adjustments.store');
        $payload = ['tipo' => 'entrada', 'cantidad' => '2.500', 'motivo' => 'Corrección de prueba'];

        foreach (['', 'Artículo seleccionado', $id + 1] as $invalid) {
            $this->actingAs($actor)->from($create)->post($store, $payload + ['articulo_id' => $invalid])
                ->assertRedirect($create)->assertSessionHasErrors('articulo_id');
        }
        $date = now('America/Santiago')->subDay()->format('Y-m-d\TH:i');
        $this->from($create)->post($store, array_replace($payload, [
            'articulo_id' => (string) $id, 'cantidad' => '0', 'ocurrido_en' => $date,
        ]))->assertRedirect($create)->assertSessionHasErrors('cantidad')
            ->assertSessionHasInput('articulo_id', (string) $id)
            ->assertSessionHasInput('tipo', 'entrada')
            ->assertSessionHasInput('cantidad', '0')
            ->assertSessionHasInput('ocurrido_en', $date)
            ->assertSessionHasInput('motivo', $payload['motivo']);
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $page = $this->get($create)->assertOk()->assertSee('Corrección de prueba')->assertSee($date);
        $this->assertMatchesRegularExpression('/<option value="'.preg_quote((string) $id, '/').'"[^>]*\bselected\b/', $page->getContent());
        $this->assertDatabaseCount('movimientos_inventario', 0);

        $this->post($store, $payload + ['articulo_id' => $id])
            ->assertRedirect(route('inventory.movements'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('articulos', ['id' => $id, 'stock_actual' => '2.500']);
        $this->assertDatabaseHas('movimientos_inventario', [
            'articulo_id' => $id, 'tipo_movimiento' => 'ajuste_manual_entrada', 'cantidad_delta' => '2.500',
        ]);
        $this->assertDatabaseCount('movimientos_inventario', 1);
    }

    public function test_movement_search_combines_text_or_a_selected_id_with_type_dates_and_pagination(): void
    {
        $actor = $this->createActor([
            PermissionCode::InventoryMovementsView, PermissionCode::InventoryAdjustmentsInput, PermissionCode::InventoryAdjustmentsOutput,
        ], 'movement_filters');
        $sprite = $this->createArticle('prueba-bebida-a-001', 'Bebida Sprite de prueba');
        DB::table('articulos')->where('id', $sprite)->update(['codigo_barras' => '0000123456789']);
        $classification = DB::table('articulos')->where('id', $sprite)->first(['categoria_articulo_id', 'unidad_id']);
        $jumex = DB::table('articulos')->insertGetId([
            ...(array) $classification, 'sku' => 'prueba-bebida-b-002', 'nombre' => 'Jugo Jumex de prueba', 'tipo_articulo' => 'reventa',
            'stock_actual' => '0.000', 'stock_minimo' => '0.000', 'activo' => true,
        ]);

        $day = now('America/Santiago')->subDays(2)->startOfDay()->addHours(12);
        $this->travelTo($day);
        $this->actingAs($actor);
        foreach (array_merge(array_fill(0, 26, $sprite), [$jumex]) as $id) {
            $this->post(route('inventory.adjustments.store'), [
                'articulo_id' => $id, 'tipo' => 'entrada', 'cantidad' => '1', 'motivo' => 'Preparación del historial',
            ])->assertRedirect(route('inventory.movements'));
        }
        $this->travelTo($day->copy()->addDay());
        $this->post(route('inventory.adjustments.store'), [
            'articulo_id' => $sprite, 'tipo' => 'salida', 'cantidad' => '3', 'motivo' => 'Preparación de salida',
        ])->assertRedirect(route('inventory.movements'));
        DB::table('articulos')->where('id', $sprite)->update(['activo' => false]);
        $originalMovements = DB::table('movimientos_inventario')->orderBy('id')->get();
        $originalArticles = DB::table('articulos')->orderBy('id')->get();

        foreach (['Bebida Sprite de prueba', 'sPr', 'PRUEBA-BEBIDA-A-001', '0000123456789', '1234567'] as $term) {
            $this->get(route('inventory.movements', ['q' => $term]))->assertOk()
                ->assertSee('Buscar artículo')->assertSee('Bebida Sprite de prueba')->assertDontSee('Jugo Jumex de prueba')
                ->assertViewHas('movements', static fn ($rows) => $rows->total() === 27);
        }
        $this->get(route('inventory.movements', ['q' => 'JuM']))->assertOk()
            ->assertSee('Jugo Jumex de prueba')->assertDontSee('Bebida Sprite de prueba');

        $filters = ['q' => 'sPr', 'tipo' => 'ajuste_manual_entrada', 'desde' => $day->toDateString(), 'hasta' => $day->toDateString()];
        $this->get(route('inventory.movements', $filters))->assertOk()
            ->assertViewHas('movements', static function ($rows) use ($filters): bool {
                parse_str(parse_url($rows->nextPageUrl(), PHP_URL_QUERY) ?? '', $query);
                return $rows->total() === 26 && array_intersect_key($query, $filters) === $filters && $query['page'] === '2';
            });
        $this->get(route('inventory.movements', $filters + ['page' => 2]))->assertOk()
            ->assertViewHas('movements', static fn ($rows) => $rows->count() === 1)
            ->assertSee('Cambio de stock')->assertSee('Stock resultante')->assertSee('+1 u');
        $this->get(route('inventory.movements', array_replace($filters, ['tipo' => 'ajuste_manual_salida'])))->assertOk()
            ->assertSee('No hay movimientos para los filtros seleccionados.');
        $this->get(route('inventory.movements', ['q' => '%_']))->assertOk()
            ->assertSee('No hay movimientos para los filtros seleccionados.');

        $this->get(route('inventory.movements', ['articulo_id' => $jumex]))->assertOk()
            ->assertViewHas('articleSearch', 'Jugo Jumex de prueba')->assertDontSee('Bebida Sprite de prueba')
            ->assertViewHas('movements', static fn ($rows) => $rows->total() === 1);
        $this->get(route('inventory.movements', ['articulo_id' => $jumex, 'q' => 'de prueba']))->assertOk()
            ->assertViewHas('movements', static fn ($rows) => $rows->total() === 1);

        $this->assertEquals($originalMovements, DB::table('movimientos_inventario')->orderBy('id')->get());
        $this->assertEquals($originalArticles, DB::table('articulos')->orderBy('id')->get());
    }

    public function test_movement_article_suggestions_are_bounded_prioritize_exact_codes_and_include_inactive_articles(): void
    {
        $id = $this->createArticle('prueba-bebida-a-001', 'Sprite de prueba');
        DB::table('articulos')->where('id', $id)->update(['codigo_barras' => '0000123456789', 'activo' => false]);
        $classification = DB::table('articulos')->where('id', $id)->first(['categoria_articulo_id', 'unidad_id']);
        DB::table('articulos')->insert(array_map(static fn (int $n) => [
            ...(array) $classification, 'sku' => 'bodega-'.$n, 'nombre' => 'Bodega 0000123456789 '.$n,
            'tipo_articulo' => 'reventa', 'stock_actual' => '0.000', 'stock_minimo' => '0.000', 'activo' => true,
        ], range(1, 30)));

        $actor = $this->createActor([PermissionCode::InventoryMovementsView], 'movement_suggestions');
        $url = route('inventory.movements.articles');
        foreach (['sPr', 'PRUEBA-BEBIDA-A-001'] as $term) {
            $this->actingAs($actor)->getJson($url.'?'.http_build_query(['q' => $term]))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $id);
        }
        $this->getJson($url.'?q=0000123456789')->assertOk()->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.id', (string) $id)->assertJsonPath('data.0.nombre', 'Sprite de prueba');
        foreach (['x', 'sin-coincidencias', '%_'] as $term) {
            $this->getJson($url.'?'.http_build_query(['q' => $term]))->assertOk()->assertExactJson(['data' => []]);
        }
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_movement_filter_and_suggestions_require_permission_and_validate_query_parameters(): void
    {
        $list = route('inventory.movements');
        $suggestions = route('inventory.movements.articles');
        $this->get($list)->assertRedirect(route('login'));
        $this->getJson($suggestions.'?q=spr')->assertUnauthorized();
        $this->actingAs($this->createActor([PermissionCode::ArticlesView], 'movement_filter_denied'));
        $this->get($list.'?q=spr')->assertForbidden();
        $this->getJson($suggestions.'?q=spr')->assertForbidden();

        $this->actingAs($this->createActor([PermissionCode::InventoryMovementsView], 'movement_filter_validation'));
        $this->getJson($suggestions.'?q%5B%5D=spr')->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson($suggestions.'?q='.str_repeat('a', 151))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson($list.'?articulo_id=-1&tipo=desconocido&desde=2026-02-30&hasta=no-fecha')
            ->assertUnprocessable()->assertJsonValidationErrors(['articulo_id', 'tipo', 'desde', 'hasta']);
    }

    public function test_inventory_web_rejects_future_local_instants_and_nonexistent_hours_before_writing(): void
    {
        $actor = $this->createActor([
            PermissionCode::InventoryAdjustmentsInput, PermissionCode::InventoryWasteRegister, PermissionCode::InventoryReceiptsCreate,
        ], 'inventory_local_time');
        $article = $this->createArticle('inventory_local_time', 'Artículo horario');
        $this->actingAs($actor);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00:00 UTC'));

        foreach ([
            ['inventory.adjustments.store', ['articulo_id' => $article, 'tipo' => 'entrada', 'cantidad' => '1', 'motivo' => 'Prueba']],
            ['inventory.wastes.store', ['articulo_id' => $article, 'cantidad' => '1', 'motivo' => 'Prueba']],
            ['receipts.store', ['tipo_origen' => 'interno', 'usuario_aportante_id' => $actor->id]],
        ] as [$route, $payload]) {
            $this->post(route($route), $payload + ['ocurrido_en' => '2026-11-08T19:30'])
                ->assertSessionHasErrors(['ocurrido_en' => 'La fecha y hora de Santiago no pueden estar en el futuro.']);
            $this->post(route($route), $payload + ['ocurrido_en' => '2026-09-06T00:30'])
                ->assertSessionHasErrors(['ocurrido_en' => 'Esta hora no existe en Santiago por el cambio de horario. Elige otra hora.']);
        }

        foreach (['movimientos_inventario', 'recepciones_mercaderia', 'mermas'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->post(route('inventory.adjustments.store'), [
            'articulo_id' => $article, 'tipo' => 'entrada', 'cantidad' => '2', 'motivo' => 'Instante local',
            'ocurrido_en' => '2026-11-08T19:00',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('movimientos_inventario', ['ocurrido_en' => '2026-11-08 22:00:00+00', 'cantidad_delta' => '2.000']);
    }

    public function test_inventory_filter_and_recent_counts_use_seven_local_calendar_days(): void
    {
        $actor = $this->createActor([
            PermissionCode::ArticlesView, PermissionCode::InventoryMovementsView,
            PermissionCode::InventoryAdjustmentsInput, PermissionCode::InventoryReceiptsCreate,
        ], 'inventory_calendar');
        $article = $this->createArticle('inventory_calendar', 'Artículo calendario');
        $this->actingAs($actor);

        foreach ([
            ['2026-11-02 02:59:59 UTC', 'Fuera de la ventana de siete días'],
            ['2026-11-02 03:00:00 UTC', 'Primer instante de los siete días'],
            ['2026-11-09 02:59:59 UTC', 'Último segundo del domingo'],
            ['2026-11-09 03:00:00 UTC', 'Primer segundo del lunes'],
        ] as [$instant, $reason]) {
            $this->travelTo(CarbonImmutable::parse($instant));
            $this->post(route('inventory.adjustments.store'), [
                'articulo_id' => $article, 'tipo' => 'entrada', 'cantidad' => '1', 'motivo' => $reason,
            ])->assertSessionHasNoErrors();
            $this->post(route('receipts.store'), ['tipo_origen' => 'interno', 'usuario_aportante_id' => $actor->id])
                ->assertSessionHasNoErrors();
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-09 02:59:59 UTC'));
        $this->get(route('inventory.index'))->assertOk()
            ->assertViewHas('metrics', fn (array $metrics) => $metrics['movements'] === 2 && $metrics['receipts'] === 2);
        $this->get(route('inventory.movements', ['desde' => '2026-11-08', 'hasta' => '2026-11-08']))->assertOk()
            ->assertSee('08/11/2026 23:59')->assertDontSee('09/11/2026 00:00')
            ->assertViewHas('movements', fn ($rows) => $rows->total() === 1
                && CarbonImmutable::parse($rows->first()->ocurrido_en)->utc()->format(DATE_ATOM) === '2026-11-09T02:59:59+00:00');
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

    private function createArticle(string $sku, string $name, string $stock = '0.000', string $minimum = '0.000', ?int $unitId = null): int
    {
        $categoryId = (int) DB::table('categorias_articulo')->insertGetId(['codigo' => 'cat_'.$sku, 'nombre' => 'Categoría '.$sku, 'activo' => true]);
        $unitId ??= (int) DB::table('unidades_medida')->insertGetId(['codigo' => 'unidad_'.$sku, 'nombre' => 'Unidad '.$sku, 'simbolo' => 'u', 'magnitud' => 'unidad', 'activo' => true]);
        return (int) DB::table('articulos')->insertGetId([
            'sku' => $sku, 'nombre' => $name, 'tipo_articulo' => 'materia_prima', 'categoria_articulo_id' => $categoryId,
            'unidad_id' => $unitId, 'stock_actual' => $stock, 'stock_minimo' => $minimum, 'activo' => true,
        ]);
    }
}
