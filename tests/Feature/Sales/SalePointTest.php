<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Sales\SaleServiceFixtures;
use Tests\TestCase;

final class SalePointTest extends TestCase
{
    use RefreshDatabase;
    use SaleServiceFixtures;

    private User $actor;

    private int $cashSessionId;

    private int $cashMethodId;

    private int $debitMethodId;

    private int $categoryId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = $this->createSalesActor([PermissionCode::SalesCreate], 'f3_actor');
        $this->categoryId = $this->createArticleCategory([
            'codigo' => $this->fixtureCode('f3_categoria'),
            'nombre' => 'Categoría F3',
        ]);
        $this->unitId = $this->createUnitOfMeasure([
            'codigo' => $this->fixtureCode('f3_unidad'),
            'nombre' => 'Unidad F3',
            'simbolo' => 'un',
            'magnitud' => 'unidad',
        ]);
        $this->cashSessionId = $this->createSalesCashSession($this->actor, 'f3_caja');
        $this->cashMethodId = $this->createPaymentMethod([
            'codigo' => 'efectivo',
            'nombre' => 'Efectivo',
            'afecta_efectivo' => true,
            'orden_presentacion' => 10,
        ]);
        $this->debitMethodId = $this->createPaymentMethod([
            'codigo' => 'debito',
            'nombre' => 'Débito',
            'afecta_efectivo' => false,
            'orden_presentacion' => 20,
        ]);
    }

    public function test_sale_point_requires_auth_and_sales_permission(): void
    {
        $this->get('/ventas/nueva')->assertRedirect('/login');

        $withoutPermission = $this->createSalesActor([], 'f3_sin_permiso');

        $this->actingAs($withoutPermission)->get('/ventas/nueva')->assertForbidden();
        $this->actingAs($withoutPermission)->getJson('/ventas/buscar?term=pan')->assertForbidden();
    }

    public function test_sale_point_loads_methods_and_keyboard_scanner_search_controls(): void
    {
        $this->actingAs($this->actor)
            ->get('/ventas/nueva')
            ->assertOk()
            ->assertSee('<h1 id="sales-title">Nueva venta</h1>', false)
            ->assertDontSee('Busca, agrega y cobra rápidamente.')
            ->assertSee('Volver al inicio')
            ->assertSee('Buscar por nombre, SKU, ID o código de barras')
            ->assertSee('Efectivo')
            ->assertSee('debito', false)
            ->assertSee('data-search-input', false)
            ->assertSee('data-payment-lines', false)
            ->assertSee('data-checkout-help', false)
            ->assertSee('data-cart-shortcut', false)
            ->assertSee('data-checkout-button disabled', false)
            ->assertSee('autofocus', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('BarcodeDetector')
            ->assertDontSee('cámara');
    }

    #[DataProvider('cashChangeExamples')]
    public function test_cash_change_examples_keep_only_net_sale_income(string $received, string $change): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '5.000', price: '5340.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->cashMethodId, '5340.00', $received),
        ]);

        $this->actingAs($this->actor)->post('/ventas', $payload)->assertRedirect(route('sales.success'));
        $this->assertDatabaseHas('pagos_venta', [
            'monto' => '5340.00', 'monto_recibido' => $received, 'vuelto' => $change,
        ]);
        $this->assertDatabaseCount('movimientos_caja', 1);
        $this->assertDatabaseHas('movimientos_caja', ['tipo_movimiento' => 'ingreso', 'monto' => '5340.00']);
        $this->assertDatabaseHas('articulos', ['id' => $articleId, 'stock_actual' => '4.000']);
        $this->assertDatabaseCount('movimientos_inventario', 1);
        $this->get(route('sales.create'))->assertOk()->assertSee('data-initial-payments="[]"', false);
    }

    public static function cashChangeExamples(): array
    {
        return ['change' => ['10000.00', '4660.00'], 'exact' => ['5340.00', '0.00']];
    }

    #[DataProvider('invalidCashReceived')]
    public function test_invalid_or_insufficient_received_cash_never_creates_operational_records(?string $received): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '5.000', price: '5340.00');
        $payload = $this->salePayload($articleId, '1.000', [$this->payment($this->cashMethodId, '5340.00', $received)]);

        $this->actingAs($this->actor)->from('/ventas/nueva')->post('/ventas', $payload)
            ->assertRedirect('/ventas/nueva')->assertSessionHasErrors('payments.0.received_amount');
        foreach (['ventas', 'pagos_venta', 'movimientos_caja', 'movimientos_inventario'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('articulos', ['id' => $articleId, 'stock_actual' => '5.000']);
    }

    public static function invalidCashReceived(): array
    {
        return ['insufficient' => ['5000.00'], 'empty' => [''], 'missing' => [null], 'negative' => ['-1'], 'invalid' => ['abc'], 'precision' => ['5340.001']];
    }

    public function test_payment_validation_errors_are_visible_in_the_pos(): void
    {
        $this->withViewErrors(['payments.0.received_amount' => 'Indica el monto recibido en efectivo.'])
            ->view('sales.create', [
                'user' => $this->actor,
                'cashSessions' => [['id' => $this->cashSessionId]],
                'paymentMethods' => [],
                'initialCart' => [],
                'initialLines' => [],
                'initialPayments' => [],
                'commandUuid' => $this->fixtureUuid(),
            ])
            ->assertSee('role="alert"', false)
            ->assertSee('Indica el monto recibido en efectivo.');
    }

    public function test_mixed_cash_change_never_posts_gross_received_or_card_amount_to_cash(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '5340.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->debitMethodId, '3000.00'),
            $this->payment($this->cashMethodId, '2340.00', '5000.00'),
        ]);

        $this->actingAs($this->actor)->post('/ventas', $payload)->assertRedirect(route('sales.success'));
        $this->assertDatabaseCount('pagos_venta', 2);
        $this->assertDatabaseHas('pagos_venta', ['metodo_pago_id' => $this->cashMethodId, 'monto' => '2340.00', 'monto_recibido' => '5000.00', 'vuelto' => '2660.00']);
        $this->assertDatabaseHas('pagos_venta', ['metodo_pago_id' => $this->debitMethodId, 'monto' => '3000.00', 'monto_recibido' => null, 'vuelto' => null]);
        $this->assertDatabaseCount('movimientos_caja', 1);
        $this->assertDatabaseHas('movimientos_caja', ['monto' => '2340.00']);
    }

    public function test_credit_is_available_and_does_not_require_received_cash(): void
    {
        $creditId = $this->createPaymentMethod(['codigo' => 'credito', 'nombre' => 'Crédito', 'afecta_efectivo' => false]);
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '5340.00');
        $this->actingAs($this->actor)->get('/ventas/nueva')->assertOk()->assertSee('credito');
        $this->post('/ventas', $this->salePayload($articleId, '1.000', [$this->payment($creditId, '5340.00')]))
            ->assertRedirect(route('sales.success'));
        $this->assertDatabaseHas('pagos_venta', ['metodo_pago_id' => $creditId, 'monto_recibido' => null, 'vuelto' => null]);
        $this->assertDatabaseCount('movimientos_caja', 0);
    }

    public function test_card_payment_rejects_received_cash_even_if_posted_manually(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '5340.00');
        $this->actingAs($this->actor)->from('/ventas/nueva')->post('/ventas', $this->salePayload($articleId, '1.000', [
            $this->payment($this->debitMethodId, '5340.00', '10000.00'),
        ]))->assertSessionHasErrors('payments.0.received_amount');
        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_inactive_payment_methods_are_not_offered_to_the_cashier(): void
    {
        $this->createPaymentMethod([
            'codigo' => 'medio_inactivo_f3',
            'nombre' => 'Medio inactivo F3',
            'activo' => false,
        ]);

        $this->actingAs($this->actor)
            ->get('/ventas/nueva')
            ->assertOk()
            ->assertDontSee('medio_inactivo_f3', false)
            ->assertDontSee('Medio inactivo F3');
    }

    public function test_exact_barcode_preserves_leading_zero_and_excludes_non_sellable_articles(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '11.000', price: '12.50');
        DB::table('articulos')->where('id', $articleId)->update(['codigo_barras' => '000123456']);
        $this->createSalesArticle($this->categoryId, $this->unitId, type: 'materia_prima', price: '3.00');
        $this->createSalesArticle($this->categoryId, $this->unitId, active: false, price: '3.00');

        $response = $this->actingAs($this->actor)->getJson('/ventas/buscar?term=000123456');

        $response->assertOk()
            ->assertJsonPath('exact_match', true)
            ->assertJsonPath('data.0.id', $articleId)
            ->assertJsonPath('data.0.codigo_barras', '000123456')
            ->assertJsonPath('data.0.stock', '11.000')
            ->assertJsonPath('data.0.stock_label', '11 un')
            ->assertJsonPath('data.0.precio', '12.50');
    }

    public function test_fractional_quantity_preserves_exact_stock_and_payment_after_checkout(): void
    {
        DB::table('unidades_medida')->where('id', $this->unitId)->update(['simbolo' => 'L', 'magnitud' => 'volumen']);
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '12.000', price: '800.00');

        $this->actingAs($this->actor)->post('/ventas', $this->salePayload($articleId, '0.035', [
            $this->payment($this->debitMethodId, '28.00'),
        ]))->assertRedirect(route('sales.success'));

        $this->assertDatabaseHas('articulos', ['id' => $articleId, 'stock_actual' => '11.965', 'precio_venta_actual' => '800.00']);
        $this->assertDatabaseHas('detalle_ventas', ['articulo_id' => $articleId, 'cantidad' => '0.035', 'precio_unitario' => '800.00']);
        $this->assertDatabaseHas('movimientos_inventario', ['articulo_id' => $articleId, 'cantidad_delta' => '-0.035', 'stock_posterior' => '11.965']);
        $this->assertDatabaseHas('pagos_venta', ['metodo_pago_id' => $this->debitMethodId, 'monto' => '28.00']);
        $this->assertDatabaseCount('movimientos_caja', 0);
    }

    public function test_cash_sale_resolves_current_price_and_calculates_change(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '5.000', price: '10000.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->cashMethodId, '10000.00', '12500.00'),
        ]);

        $response = $this->actingAs($this->actor)->post('/ventas', $payload);

        $response->assertRedirect(route('sales.success'));
        $this->get(route('sales.success'))
            ->assertOk()
            ->assertSee('Venta realizada')
            ->assertSee('$2.500');
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseHas('detalle_ventas', [
            'articulo_id' => $articleId,
            'precio_unitario' => '10000.00',
            'cantidad' => '1.000',
        ]);
        $this->assertDatabaseHas('pagos_venta', [
            'monto' => '10000.00',
            'monto_recibido' => '12500.00',
            'vuelto' => '2500.00',
        ]);
    }

    public function test_debit_sale_does_not_accept_cash_received_fields(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '8.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->debitMethodId, '8.00'),
        ]);

        $this->actingAs($this->actor)->post('/ventas', $payload)->assertRedirect(route('sales.success'));
        $this->assertDatabaseHas('pagos_venta', [
            'metodo_pago_id' => $this->debitMethodId,
            'monto' => '8.00',
            'monto_recibido' => null,
            'vuelto' => null,
        ]);
    }

    public function test_mixed_payment_requires_exact_total_and_persists_each_method(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '20.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->cashMethodId, '5.00', '5.00'),
            $this->payment($this->debitMethodId, '15.00'),
        ]);

        $this->actingAs($this->actor)->post('/ventas', $payload)->assertRedirect(route('sales.success'));
        $this->assertDatabaseCount('pagos_venta', 2);
        $this->assertSame(['5.00', '15.00'], DB::table('pagos_venta')->orderBy('id')->pluck('monto')->all());
    }

    public function test_payment_shortfall_is_rejected_without_creating_a_sale(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '20.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->debitMethodId, '19.99'),
        ]);

        $this->actingAs($this->actor)
            ->from('/ventas/nueva')
            ->post('/ventas', $payload)
            ->assertRedirect('/ventas/nueva')
            ->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_stock_shortage_is_reported_and_does_not_create_partial_state(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '1.000', price: '10.00');
        $payload = $this->salePayload($articleId, '2.000', [
            $this->payment($this->debitMethodId, '20.00'),
        ]);

        $this->actingAs($this->actor)
            ->from('/ventas/nueva')
            ->post('/ventas', $payload)
            ->assertRedirect('/ventas/nueva')
            ->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('ventas', 0);
        $this->assertSame('1.000', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
    }

    public function test_closed_or_missing_cash_session_blocks_checkout(): void
    {
        DB::table('sesiones_caja')->where('id', $this->cashSessionId)->update([
            'cerrada_en' => now()->addSecond(),
            'usuario_cierre_id' => $this->actor->getKey(),
            'monto_cierre_declarado' => '0.00',
            'monto_teorico_cierre' => '0.00',
        ]);
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '10.00');

        $this->actingAs($this->actor)
            ->get('/ventas/nueva')
            ->assertOk()
            ->assertSee('Debes abrir una sesión de caja vigente antes de cobrar.')
            ->assertDontSee('Ir a Caja');

        $this->actingAs($this->actor)
            ->from('/ventas/nueva')
            ->post('/ventas', $this->salePayload($articleId, '1.000', [
                $this->payment($this->debitMethodId, '10.00'),
            ]))
            ->assertRedirect('/ventas/nueva')
            ->assertSessionHasErrors('cash_session_id');
    }

    public function test_compact_cash_notice_links_to_cash_only_with_effective_permission(): void
    {
        DB::table('sesiones_caja')->where('id', $this->cashSessionId)->update([
            'cerrada_en' => now()->addSecond(),
            'usuario_cierre_id' => $this->actor->getKey(),
            'monto_cierre_declarado' => '0.00',
            'monto_teorico_cierre' => '0.00',
        ]);
        $allowed = $this->createSalesActor([PermissionCode::CashSessionsView], 'f3_caja_permitida');
        $this->attachPermission((int) $allowed->rol_id, (int) DB::table('permisos')->where('codigo', PermissionCode::SalesCreate->value)->value('id'));
        $this->actingAs($allowed)->get('/ventas/nueva')->assertOk()
            ->assertSee('Debes abrir una sesión de caja vigente antes de cobrar.')
            ->assertSee('Ir a Caja')->assertSee('href="'.route('cash.index').'"', false);
        $this->actingAs($this->actor)->get('/ventas/nueva')->assertOk()->assertDontSee('Ir a Caja');
    }

    #[DataProvider('invalidCashSessionPayments')]
    public function test_invalid_jornada_blocks_checkout_before_any_sale_payment_or_stock_change(string $method, bool $legacy): void
    {
        if ($legacy) {
            DB::table('sesiones_caja')->where('id', $this->cashSessionId)->update([
                'fecha_operativa' => null, 'fondo_autorizado_id' => null, 'clave_idempotencia_apertura' => null,
            ]);
        } else {
            $this->travel(1)->days();
        }
        $credit = $this->createPaymentMethod(['codigo' => 'credito', 'nombre' => 'Crédito', 'afecta_efectivo' => false]);
        $article = $this->createSalesArticle($this->categoryId, $this->unitId, stock: '12.000', price: '800.00');
        $payments = match ($method) {
            'cash' => [$this->payment($this->cashMethodId, '800.00', '1000.00')],
            'debit' => [$this->payment($this->debitMethodId, '800.00')],
            'credit' => [$this->payment($credit, '800.00')],
            'mixed' => [$this->payment($this->cashMethodId, '300.00', '500.00'), $this->payment($credit, '500.00')],
        };
        $this->actingAs($this->actor)->get('/ventas/nueva')->assertOk()
            ->assertSee($legacy ? 'no tiene una jornada vigente' : 'sesión de caja anterior pendiente de cierre')
            ->assertSee('name="cash_session_id" value=""', false)
            ->assertSee('data-checkout-button disabled', false)
            ->assertDontSee('Todo listo para completar la venta');
        $this->from('/ventas/nueva')->post('/ventas', $this->salePayload($article, '1.000', $payments))
            ->assertRedirect('/ventas/nueva')->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('pagos_venta', 0);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseHas('articulos', ['id' => $article, 'stock_actual' => '12.000']);
    }

    public static function invalidCashSessionPayments(): array
    {
        return [
            'efectivo' => ['cash', false],
            'débito' => ['debit', false],
            'crédito' => ['credit', false],
            'mixto' => ['mixed', false],
            'sesión sin fecha' => ['cash', true],
        ];
    }

    public function test_repeating_same_checkout_uuid_is_idempotent(): void
    {
        $articleId = $this->createSalesArticle($this->categoryId, $this->unitId, price: '7.00');
        $payload = $this->salePayload($articleId, '1.000', [
            $this->payment($this->debitMethodId, '7.00'),
        ]);

        $this->actingAs($this->actor)->post('/ventas', $payload)->assertRedirect(route('sales.success'));
        $this->actingAs($this->actor)->post('/ventas', $payload)->assertRedirect(route('sales.success'));
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('pagos_venta', 1);
        $this->assertDatabaseCount('movimientos_inventario', 1);
    }

    /** @param list<array<string, mixed>> $payments @return array<string, mixed> */
    private function salePayload(int $articleId, string $quantity, array $payments): array
    {
        return [
            'command_uuid' => $this->fixtureUuid(),
            'cash_session_id' => $this->cashSessionId,
            'lines' => [[
                'article_id' => $articleId,
                'quantity' => $quantity,
            ]],
            'payments' => $payments,
        ];
    }

    /** @return array<string, mixed> */
    private function payment(int $methodId, string $amount, ?string $received = null): array
    {
        return [
            'payment_method_id' => $methodId,
            'amount' => $amount,
            'received_amount' => $received,
            'payment_uuid' => $this->fixtureUuid(),
        ];
    }
}
