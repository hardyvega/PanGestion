<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Data\Orders\CancelOrderCommand;
use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\DeliverOrderCommand;
use App\Data\Orders\FreeOrderData;
use App\Data\Orders\OrderContact;
use App\Data\Orders\OrderLineData;
use App\Data\Orders\OrderPaymentData;
use App\Data\Sales\AnnulSaleCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Enums\PaymentType;
use App\Enums\PermissionCode as P;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\SesionCaja;
use App\Models\User;
use App\Models\Venta;
use App\Services\Cash\CashPostingService;
use App\Services\Cash\CashFundService;
use App\Services\Cash\CashService;
use App\Services\Dashboard\DashboardService;
use App\Services\Orders\OrderService;
use App\Services\Sales\RecentSalesService;
use App\Services\Sales\SaleService;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\Support\Cash\DerivedCashPostingFixtures;
use Tests\TestCase;

final class DailyCashPaymentsTest extends TestCase
{
    use DailyCashFixtures;
    use DerivedCashPostingFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private SesionCaja $session;

    private array $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00:00 UTC'));
        $this->owner = $this->dailyActor([P::CashFundsAuthorize, P::CashSessionsClose, P::CashSessionsView]);
        $this->cashier = $this->dailyActor([P::CashSessionsOpen, P::SalesCreate, P::SalesAnnul, P::OrdersCreate, P::OrdersCollect, P::OrdersCancel, P::OrdersDeliver]);
        $register = (int) DB::table('cajas')->insertGetId(['codigo' => 'principal', 'nombre' => 'Caja principal', 'activo' => true]);
        $this->session = $this->dailyOpen($this->cashier, $register, '100');
        $this->context = $this->createPostingContext($this->cashier, true);
        $this->context['cashSessionId'] = (int) $this->session->id;
    }

    public function test_mixed_payment_components_are_not_double_counted_as_cash_or_sales(): void
    {
        $card = $this->createPaymentMethod(['codigo' => 'debito', 'nombre' => 'Débito', 'afecta_efectivo' => false]);
        $sale = app(SaleService::class)->complete($this->cashier, new CompleteSaleCommand(
            (string) Str::uuid(), (int) $this->session->id,
            [new SaleLineData($this->context['articleId'], '1.000', '4.00')],
            [
                new SalePaymentData($this->context['paymentMethodId'], PaymentType::Charge, '1.00', (int) $this->session->id, (string) Str::uuid(), '5.00', '4.00'),
                new SalePaymentData($card, PaymentType::Charge, '3.00', (int) $this->session->id, (string) Str::uuid()),
            ],
        ));
        $closed = $this->dailyClose($this->owner, $this->session, '101', '50');
        $summary = $closed->cierre->resumen;
        $this->assertSame('101.00', (string) $closed->cierre->esperado_antes_retiro);
        $this->assertSame('4.00', $summary['sales_total']);
        $this->assertSame('1.00', $summary['totals']['cash_collections']);
        $this->assertSame('3.00', $summary['methods']['debito']['net']);
        $this->assertCount(2, $summary['payments']);
        $this->assertSame($sale->id, (int) $summary['payments'][0]['operation_id']);
        $this->assertSame($this->cashier->id, (int) $summary['payments'][0]['user_id']);
        $this->assertNotEmpty($summary['payments'][0]['user_name']);
        $this->assertSame(1, DB::table('movimientos_caja')->whereNotNull('pago_venta_id')->count());
    }

    public function test_sale_annulment_after_closure_has_no_refund_or_inventory_effect(): void
    {
        $sale = $this->sale();
        $closed = $this->dailyClose($this->owner, $this->session, '104', '50');
        $summary = $closed->cierre->resumen;
        $stock = DB::table('articulos')->where('id', $this->context['articleId'])->value('stock_actual');
        $ledger = DB::table('movimientos_inventario')->count();
        try {
            app(SaleService::class)->annul($this->cashier, new AnnulSaleCommand((int) $sale->id, (string) Str::uuid(), 'Solicitud posterior al cierre'));
            $this->fail('Se alteró un período cerrado');
        } catch (InventoryDomainException $e) {
            $this->assertStringContainsString('cerrada', $e->getMessage());
        }
        $this->assertSame('completada', $sale->fresh()->estado->value);
        $this->assertSame(0, DB::table('pagos_venta')->where('tipo', 'devolucion')->count());
        $this->assertSame($stock, DB::table('articulos')->where('id', $this->context['articleId'])->value('stock_actual'));
        $this->assertSame($ledger, DB::table('movimientos_inventario')->count());
        $this->assertSame($summary, $closed->cierre->fresh()->resumen);
    }

    public function test_order_refund_reverses_the_pos_collection_and_restores_cash_balance(): void
    {
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $orders = app(OrderService::class);
        $order = $orders->create($this->cashier, new CreateOrderCommand(
            (string) Str::uuid(), $this->context['clientId'],
            [new OrderLineData($this->context['articleId'], '1.000', '4.00')],
            [new OrderPaymentData($cash, PaymentType::Charge, '4.00', (int) $this->session->id, (string) Str::uuid())],
        ));
        $recent = app(RecentSalesService::class);
        $this->assertSame(['cash' => '$4', 'digital' => '$0'], $recent->current()['collected']);

        $orders->cancel($this->cashier, new CancelOrderCommand((int) $order->id, (string) Str::uuid(), 'Devolución de prueba'));

        $this->assertSame(['cash' => '$0', 'digital' => '$0'], $recent->current()['collected']);
        $this->assertSame('100.00', app(CashService::class)->overview($this->owner, (int) $this->session->id)['theoretical']);
        $this->assertDatabaseCount('pagos_pedido', 2);
        $this->assertDatabaseCount('movimientos_caja', 2);
        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_order_cancellation_after_closure_has_no_refund_or_inventory_effect(): void
    {
        $order = app(OrderService::class)->create($this->cashier, new CreateOrderCommand(
            (string) Str::uuid(), $this->context['clientId'],
            [new OrderLineData($this->context['articleId'], '1.000', '4.00')],
            [new OrderPaymentData($this->context['paymentMethodId'], PaymentType::Charge, '4.00', (int) $this->session->id, (string) Str::uuid())],
        ));
        $closed = $this->dailyClose($this->owner, $this->session, '104', '50');
        try {
            app(OrderService::class)->cancel($this->cashier, new CancelOrderCommand((int) $order->id, (string) Str::uuid(), 'Solicitud posterior al cierre'));
            $this->fail('Se aceptó cancelación sobre caja cerrada');
        } catch (InventoryDomainException $e) {
            $this->assertStringContainsString('cerrada', $e->getMessage());
        }
        $this->assertSame('pendiente', $order->fresh()->estado->value);
        $this->assertSame(0, DB::table('pagos_pedido')->where('tipo', 'devolucion')->count());
        $this->assertSame(2, DB::table('movimientos_caja')->where('sesion_caja_id', $this->session->id)->count());
        $this->assertSame('104.00', (string) $closed->cierre->fresh()->esperado_antes_retiro);
    }

    public function test_late_cash_posting_is_rejected_and_existing_posting_remains_idempotent(): void
    {
        $sale = $this->sale();
        $payment = DB::table('pagos_venta')->where('venta_id', $sale->id)->sole();
        $this->dailyClose($this->owner, $this->session, '104', '50');
        $before = DB::table('movimientos_caja')->count();
        app(CashPostingService::class)->postSalePayment((int) $payment->id);
        $this->assertSame($before, DB::table('movimientos_caja')->count());
        $late = $this->createSalePayment((int) $sale->id, $this->context['paymentMethodId'], (int) $this->session->id, (int) $this->cashier->id, (string) Str::uuid(), ['monto' => '1.00', 'ocurrido_en' => now('UTC')]);
        $this->expectException(InventoryDomainException::class);
        app(CashPostingService::class)->postSalePayment($late);
    }

    public function test_non_cash_annulment_is_also_rejected_after_definitive_closure(): void
    {
        DB::table('metodos_pago')->where('id', $this->context['paymentMethodId'])->update(['afecta_efectivo' => false]);
        $sale = $this->sale();
        $this->dailyClose($this->owner, $this->session, '100', '50');
        try {
            app(SaleService::class)->annul($this->cashier, new AnnulSaleCommand((int) $sale->id, (string) Str::uuid(), 'Devolución electrónica tardía'));
            $this->fail('Se aceptó devolución de un período cerrado');
        } catch (InventoryDomainException $e) {
            $this->assertStringContainsString('cerrada', $e->getMessage());
        }
        $this->assertSame(0, DB::table('pagos_venta')->where('tipo', 'devolucion')->count());
        $this->assertSame('completada', $sale->fresh()->estado->value);
    }

    public function test_prepaid_delivery_cannot_publish_a_new_sale_into_a_closed_session(): void
    {
        $order = app(OrderService::class)->create($this->cashier, new CreateOrderCommand(
            (string) Str::uuid(), $this->context['clientId'],
            [new OrderLineData($this->context['articleId'], '1.000', '4.00')],
            [new OrderPaymentData($this->context['paymentMethodId'], PaymentType::Charge, '4.00', (int) $this->session->id, (string) Str::uuid())],
        ));
        DB::table('pedidos')->where('id', $order->id)->update(['estado' => 'listo']);
        $this->dailyClose($this->owner, $this->session, '104', '50');
        try {
            app(OrderService::class)->deliver($this->cashier, new DeliverOrderCommand((int) $order->id, (string) Str::uuid()));
            $this->fail('Se publicó una venta nueva en una caja cerrada');
        } catch (InventoryDomainException $e) {
            $this->assertStringContainsString('sesión vigente de Caja principal', $e->getMessage());
        }
        $this->assertSame('listo', $order->fresh()->estado->value);
        $this->assertSame(0, DB::table('ventas')->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame('10.000', DB::table('articulos')->where('id', $this->context['articleId'])->value('stock_actual'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_prepaid_order_without_client_delivers_in_next_journey_without_touching_the_closed_cash(bool $free): void
    {
        $order = app(OrderService::class)->create($this->cashier, new CreateOrderCommand(
            (string) Str::uuid(), null,
            $free ? [] : [new OrderLineData($this->context['articleId'], '1.000', '4.00')],
            [new OrderPaymentData($this->context['paymentMethodId'], PaymentType::Charge, '4.00', (int) $this->session->id, (string) Str::uuid(), '4.00', '0.00')],
            CarbonImmutable::parse('2026-10-20 16:00:00', 'America/Santiago'),
            contact: new OrderContact('Cliente de prueba', '9 1234-5678'),
            freeOrder: $free ? new FreeOrderData('Pie de limón', null, '4') : null,
        ));
        DB::table('pedidos')->where('id', $order->id)->update(['estado' => 'listo']);
        $this->dailyClose($this->owner, $this->session, '104', '50');
        $closure = DB::table('cierres_caja')->where('sesion_caja_id', $this->session->id)->value('resumen');
        $payment = DB::table('pagos_pedido')->where('pedido_id', $order->id)->first();
        $movements = DB::table('movimientos_caja')->count();

        $this->travel(1)->days();
        $fund = app(CashFundService::class)->available((int) $this->session->caja_id);
        $newSession = app(CashService::class)->open($this->cashier, (int) $this->session->caja_id, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
        $deliverer = $this->dailyActor([P::OrdersDeliver]);
        $payload = ['command_uuid' => (string) Str::uuid()];
        $url = route('orders.deliver', $order);
        $this->actingAs($deliverer)->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order));
        $this->post($url, $payload)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('pedidos', ['id' => $order->id, 'estado' => 'entregado']);
        $this->assertDatabaseHas('ventas', ['pedido_id' => $order->id, 'cliente_id' => null, 'sesion_caja_id' => $newSession->id, 'monto_anticipo_aplicado' => '4.00']);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('pagos_pedido', 1);
        $this->assertDatabaseCount('pagos_venta', 0);
        $this->assertDatabaseCount('movimientos_caja', $movements);
        $this->assertSame((array) $payment, (array) DB::table('pagos_pedido')->where('id', $payment->id)->first());
        $this->assertSame($closure, DB::table('cierres_caja')->where('sesion_caja_id', $this->session->id)->value('resumen'));
        $this->assertDatabaseCount('detalle_ventas', $free ? 0 : 1);
        $this->assertDatabaseCount('movimientos_inventario', $free ? 0 : 1);
        $this->assertDatabaseHas('articulos', ['id' => $this->context['articleId'], 'stock_actual' => $free ? '10.000' : '9.000']);
    }

    public function test_free_order_receipts_follow_real_payment_day_and_delivery_records_one_sale_without_stock(): void
    {
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $debit = $this->createPaymentMethod(['codigo' => 'debito', 'nombre' => 'Débito', 'afecta_efectivo' => false]);
        $credit = $this->createPaymentMethod(['codigo' => 'credito', 'nombre' => 'Crédito', 'afecta_efectivo' => false]);
        $orders = app(OrderService::class);
        $dashboard = app(DashboardService::class);
        $order = $orders->create($this->cashier, new CreateOrderCommand(
            (string) Str::uuid(), null, [], [
                new OrderPaymentData($cash, PaymentType::Charge, '10000.00', (int) $this->session->id, (string) Str::uuid(), '10000.00', '0.00'),
                new OrderPaymentData($debit, PaymentType::Charge, '4000.00', (int) $this->session->id, (string) Str::uuid()),
                new OrderPaymentData($credit, PaymentType::Charge, '1000.00', (int) $this->session->id, (string) Str::uuid()),
            ], CarbonImmutable::parse('2026-10-20 16:00:00', 'America/Santiago'),
            contact: new OrderContact('Cliente de prueba', '9 1234-5678'),
            freeOrder: new FreeOrderData('Torta de prueba', 'Dedicatoria privada', '25.000'),
        ));
        $today = $dashboard->summary($this->cashier);
        $this->assertSame(['0.00', '10000.00', '4000.00'], [$today->salesToday, $today->cashToday, $today->debitToday]);
        $this->assertNull($today->salesWeek);
        $recent = app(RecentSalesService::class);
        $this->assertSame(['cash' => '$10.000', 'digital' => '$5.000'], $recent->current()['collected']);
        $this->dailyClose($this->owner, $this->session, '10100', '50');
        $closure = DB::table('cierres_caja')->where('sesion_caja_id', $this->session->id)->value('resumen');
        $this->travel(1)->days();
        $fund = app(CashFundService::class)->available((int) $this->session->caja_id);
        $newSession = app(CashService::class)->open($this->cashier, (int) $this->session->caja_id, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
        DB::table('pedidos')->where('id', $order->id)->update(['estado' => 'listo']);
        $command = new DeliverOrderCommand((int) $order->id, (string) Str::uuid(), new OrderPaymentData(
            $cash, PaymentType::Charge, '10000.00', (int) $newSession->id, (string) Str::uuid(), '10000.00', '0.00',
        ));
        $orders->deliver($this->cashier, $command);
        $orders->deliver($this->cashier, $command);

        $sale = Venta::query()->where('pedido_id', $order->id)->sole();
        $tomorrow = $dashboard->summary($this->cashier);
        $this->assertSame(['25000.00', '10000.00', '0.00'], [$tomorrow->salesToday, $tomorrow->cashToday, $tomorrow->debitToday]);
        $this->assertSame('15000.00', $sale->monto_anticipo_aplicado);
        $this->assertDatabaseCount('pagos_venta', 0);
        $this->assertDatabaseCount('pagos_pedido', 4);
        $this->assertDatabaseCount('detalle_ventas', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseHas('articulos', ['id' => $this->context['articleId'], 'stock_actual' => '10.000']);
        $this->assertSame($closure, DB::table('cierres_caja')->where('sesion_caja_id', $this->session->id)->value('resumen'));

        $current = $recent->current();
        $this->assertSame('Pedido N.º '.$order->id, $current['rows'][0]['products']);
        $this->assertSame(['cash' => '$10.000', 'digital' => '$0'], $current['collected']);
        $this->assertStringNotContainsString('Dedicatoria privada', json_encode($current));
        $this->assertStringNotContainsString('912345678', json_encode($current));

        try {
            app(SaleService::class)->annul($this->cashier, new AnnulSaleCommand((int) $sale->id, (string) Str::uuid(), 'Pedido devuelto'));
            $this->fail('Se aceptó una anulación sin reversión financiera autorizada.');
        } catch (InventoryDomainException $exception) {
            $this->assertStringContainsString('pedido por encargo todavía no se puede anular', $exception->getMessage());
        }
        $this->assertDatabaseHas('ventas', ['id' => $sale->id, 'estado' => 'completada']);
        $this->assertDatabaseCount('pagos_pedido', 4);
    }

    public function test_another_cashier_can_sell_in_the_shared_session_with_their_own_responsibility(): void
    {
        $other = $this->dailyActor([P::SalesCreate]);
        $sale = $this->sale($other);
        $this->assertSame($other->id, (int) $sale->usuario_id);
        $this->assertSame($other->id, (int) DB::table('pagos_venta')->where('venta_id', $sale->id)->sole()->usuario_id);
        $this->assertSame($other->id, (int) DB::table('movimientos_caja')->where('sesion_caja_id', $this->session->id)->sole()->usuario_id);
        $this->assertSame($this->cashier->id, $this->session->fresh()->usuario_apertura_id);
    }

    private function sale(?User $actor = null): Venta
    {
        return app(SaleService::class)->complete($actor ?? $this->cashier, new CompleteSaleCommand(
            (string) Str::uuid(), (int) $this->session->id,
            [new SaleLineData($this->context['articleId'], '1.000', '4.00')],
            [new SalePaymentData($this->context['paymentMethodId'], PaymentType::Charge, '4.00', (int) $this->session->id, (string) Str::uuid())],
        ));
    }
}
