<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Enums\PermissionCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Sales\SaleServiceFixtures;
use Tests\TestCase;

final class RecentSalesTest extends TestCase
{
    use RefreshDatabase;
    use SaleServiceFixtures;

    private User $cashier;
    private int $register;
    private int $session;
    private int $article;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 21:00:00-03'));
        $this->cashier = $this->createSalesActor([PermissionCode::SalesCreate]);
        $this->register = $this->createCashRegister(['codigo' => 'principal']);
        $this->session = $this->createOperationalCashSession($this->register, $this->cashier->id);
        $this->article = $this->createSalesArticle($this->createArticleCategory(), $this->createUnitOfMeasure(), price: '800.00');
        $this->travelTo(CarbonImmutable::parse('2026-11-07 01:30:00 UTC'));
    }

    public function test_list_is_limited_to_the_ten_latest_completed_sales_of_the_shared_principal_session(): void
    {
        $colleague = $this->createSalesActor();
        $this->attachPermission($colleague->rol_id, $this->salesPermissionId());
        $colleague->forceFill(['nombre' => 'Cajera', 'apellido' => 'Colega', 'correo' => 'privado-operadora@example.test'])->save();
        $client = $this->createClient(['nombre' => 'Nombre privado del cliente']);
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);

        for ($number = 1; $number <= 12; $number++) {
            $operator = $number % 2 === 0 ? $colleague : $this->cashier;
            $sale = $this->recordSale($operator, $number, [
                'cliente_id' => $client, 'completada_en' => now('UTC')->subMinutes(12 - min($number, 11)),
            ]);
            $this->addPayment($sale, $cash, '1000', ['usuario_id' => $operator->id]);
        }
        $other = $this->createOperationalCashSession($this->createCashRegister(['codigo' => 'otra', 'nombre' => 'Otra caja']), $colleague->id);
        $closed = $this->createCashSession($this->register, $colleague->id, [
            'abierta_en' => now('UTC')->subHours(6), 'cerrada_en' => now('UTC')->subHours(3),
            'usuario_cierre_id' => $colleague->id, 'monto_cierre_declarado' => '0', 'monto_teorico_cierre' => '0',
        ]);
        $otherSale = $this->recordSale($colleague, 20, ['sesion_caja_id' => $other]);
        $this->addPayment($otherSale, $cash, '1000', ['sesion_caja_id' => $other]);
        $closedSale = $this->recordSale($colleague, 21, ['sesion_caja_id' => $closed, 'completada_en' => now('UTC')->subHours(4)]);
        $this->addPayment($closedSale, $cash, '1000', ['sesion_caja_id' => $closed]);
        $pastSale = $this->recordSale($colleague, 22, ['fecha_comercial' => '2026-11-05', 'completada_en' => now('UTC')->subDay()]);
        $this->addPayment($pastSale, $cash, '1000');
        $voided = $this->recordSale($colleague, 23, [
            'estado' => 'anulada', 'usuario_anulador_id' => $colleague->id, 'anulada_en' => now('UTC'),
            'motivo_anulacion' => 'Anulación de prueba', 'clave_idempotencia_anulacion' => (string) Str::uuid(),
        ]);
        $this->addPayment($voided, $cash, '1000');
        $this->addPayment($voided, $cash, '1000', ['tipo' => 'devolucion']);

        $this->actingAs($this->cashier)->get(route('sales.create'))->assertOk()->assertSee('Ventas recientes')
            ->assertSee(route('sales.recent', $this->session))->assertSee('Cajera Colega')->assertDontSee('Nombre privado del cliente')
            ->assertSeeInOrder(['Ventas recientes', 'Total cobrado en efectivo', '$12.000', 'Total cobrado por medios digitales', '$0']);
        $html = $this->getJson(route('sales.recent', $this->session))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('collected.cash', '$12.000')->assertJsonPath('collected.digital', '$0')->json('html');
        preg_match_all('/N\.º (\d+)/u', $html, $matches);
        $this->assertSame(array_map('strval', range(12, 3)), $matches[1]);
        $this->assertStringContainsString('22:29', $html);
        $this->assertStringContainsString('Cajera Colega', $html);
        $this->assertStringContainsString($this->cashier->nombre, $html);
        $this->assertStringNotContainsString('Nombre privado del cliente', $html);
        $this->assertStringNotContainsString('privado-operadora@example.test', $html);
        $this->assertStringNotContainsString('Anulación de prueba', $html);
        $this->assertDatabaseCount('ventas', 16);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_payment_labels_keep_one_total_per_sale_and_include_derived_order_payments(): void
    {
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $debit = $this->createPaymentMethod(['codigo' => 'debito', 'nombre' => 'Débito']);
        $credit = $this->createPaymentMethod(['codigo' => 'credito', 'nombre' => 'Crédito']);
        foreach ([$cash, $debit, $credit] as $index => $method) {
            $sale = $this->recordSale($this->cashier, $index + 1, ['total' => '800']);
            $this->addPayment($sale, $method, '800');
        }
        $mixed = $this->recordSale($this->cashier, 4, ['total' => '1500']);
        $this->addPayment($mixed, $cash, '500');
        $this->addPayment($mixed, $cash, '300');
        $this->addPayment($mixed, $debit, '700');
        $this->createSaleDetail($mixed, $this->article, [
            'articulo_nombre_snapshot' => '<b>Nombre guardado</b>', 'cantidad' => '1.250', 'precio_unitario' => '800', 'subtotal' => '1000',
        ]);
        foreach (['150', '150', '200'] as $index => $amount) {
            $this->createSaleDetail($mixed, $this->article, [
                'articulo_nombre_snapshot' => 'Línea '.($index + 1), 'precio_unitario' => $amount, 'subtotal' => $amount,
            ]);
        }
        $client = $this->createClient();
        $order = $this->createOrder($client, $this->cashier->id, ['estado' => 'entregado', 'entregado_en' => now('UTC')]);
        $this->recordSale($this->cashier, 5, ['pedido_id' => $order, 'cliente_id' => $client, 'total' => '700']);
        $this->createOrderPayment($order, $credit, $this->session, $this->cashier->id, (string) Str::uuid(), ['monto' => '700']);

        $html = $this->actingAs($this->cashier)->getJson(route('sales.recent', $this->session))->assertOk()
            ->assertJsonPath('collected.cash', '$1.600')->assertJsonPath('collected.digital', '$2.300')->json('html');
        $this->assertSame(1, substr_count($html, '$1.500'));
        $this->assertSame(1, substr_count($html, 'Pago mixto'));
        $this->assertSame(1, substr_count($html, 'Efectivo'));
        $this->assertSame(1, substr_count($html, 'Débito'));
        $this->assertSame(2, substr_count($html, 'Crédito'));
        $this->assertStringContainsString('1,25 kg de &lt;b&gt;Nombre guardado&lt;/b&gt;', $html);
        $this->assertStringContainsString('y más', $html);
        $this->assertStringNotContainsString('Línea 3', $html);
    }

    public function test_no_sales_means_zero_even_with_opening_fund_and_manual_movements(): void
    {
        DB::table('sesiones_caja')->where('id', $this->session)->update(['monto_inicial' => '60000']);
        $this->createCashMovement($this->session, $this->cashier->id, ['monto' => '5000']);
        $this->createCashMovement($this->session, $this->cashier->id, ['monto' => '1000', 'tipo_movimiento' => 'egreso']);
        $this->createCashMovement($this->session, $this->cashier->id, ['monto' => '2000', 'tipo_movimiento' => 'retiro']);

        $this->actingAs($this->cashier)->get(route('sales.create'))->assertOk()
            ->assertSeeInOrder(['Total cobrado en efectivo', '$0', 'Total cobrado por medios digitales', '$0']);
        $this->getJson(route('sales.recent', $this->session))->assertOk()
            ->assertJsonPath('collected.cash', '$0')->assertJsonPath('collected.digital', '$0');
        $this->assertDatabaseCount('movimientos_caja', 3);
    }

    public function test_totals_keep_nonzero_cents_and_can_exceed_an_individual_payment_limit(): void
    {
        $credit = $this->createPaymentMethod(['codigo' => 'credito', 'nombre' => 'Crédito']);
        foreach (['999999999999.99', '0.02'] as $index => $amount) {
            $sale = $this->recordSale($this->cashier, $index + 1, ['total' => $amount]);
            $this->addPayment($sale, $credit, $amount);
        }

        $this->actingAs($this->cashier)->getJson(route('sales.recent', $this->session))->assertOk()
            ->assertJsonPath('collected.cash', '$0')->assertJsonPath('collected.digital', '$1.000.000.000.000,01');
    }

    public function test_endpoint_requires_effective_sales_permission_and_current_principal_session(): void
    {
        $url = route('sales.recent', $this->session);
        $this->getJson($url)->assertUnauthorized()->assertJsonMissingPath('collected');
        $this->actingAs($this->createSalesActor())->getJson($url)->assertForbidden()->assertJsonMissingPath('collected');
        $this->actingAs($this->cashier)->getJson($url)->assertOk();
        $other = $this->createOperationalCashSession($this->createCashRegister(['codigo' => 'otra', 'nombre' => 'Otra caja']), $this->cashier->id);
        $this->getJson(route('sales.recent', $other))->assertStatus(409)->assertJsonMissingPath('html')->assertJsonMissingPath('collected');
        DB::table('permisos')->where('id', $this->salesPermissionId())->update(['activo' => false]);
        $this->getJson($url)->assertForbidden()->assertJsonMissingPath('html')->assertJsonMissingPath('collected');
    }

    public function test_rollover_stops_the_old_list_even_when_principal_opens_again(): void
    {
        $url = route('sales.recent', $this->session);
        $this->actingAs($this->cashier)->getJson($url)->assertOk();
        $this->travel(1)->days();
        $this->getJson($url)->assertStatus(409)->assertJsonMissingPath('html')->assertJsonMissingPath('collected');
        $this->get(route('sales.create'))->assertOk()->assertSee('Caja principal necesita una sesión vigente')
            ->assertDontSee('data-pos-recent-url');
        DB::table('sesiones_caja')->where('id', $this->session)->update([
            'cerrada_en' => now('UTC'), 'usuario_cierre_id' => $this->cashier->id,
            'monto_cierre_declarado' => '0', 'monto_teorico_cierre' => '0',
        ]);
        DB::table('cajas')->where('id', $this->register)->update(['activo' => false, 'codigo' => 'retirada']);
        $new = $this->createOperationalCashSession($this->createCashRegister(['codigo' => 'principal', 'nombre' => 'Caja principal vigente']), $this->cashier->id);
        $this->getJson($url)->assertStatus(409)->assertJsonMissingPath('html');
        $this->getJson(route('sales.recent', $new))->assertOk();
    }

    public function test_closed_cash_session_hides_totals_even_before_the_jornada_changes(): void
    {
        DB::table('sesiones_caja')->where('id', $this->session)->update([
            'cerrada_en' => now('UTC'), 'usuario_cierre_id' => $this->cashier->id,
            'monto_cierre_declarado' => '0', 'monto_teorico_cierre' => '0',
        ]);
        $this->actingAs($this->cashier)->getJson(route('sales.recent', $this->session))->assertStatus(409)->assertJsonMissingPath('collected');
        $this->get(route('sales.create'))->assertOk()->assertDontSee('Total cobrado en efectivo');
    }

    public function test_poll_reads_login_without_extending_it_or_consuming_confirmation_flash(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'recent-sales-session-'.Str::uuid();
        mkdir($directory);
        config(['session.driver' => 'file', 'session.files' => $directory, 'session.lifetime' => 1]);
        $session = app(SessionManager::class)->forgetDrivers()->driver();
        $session->start();
        $session->put(app('auth')->guard()->getName(), $this->cashier->id);
        $session->flash('sale_result', 123);
        $session->save();
        $file = $directory.DIRECTORY_SEPARATOR.$session->getId();

        try {
            $original = file_get_contents($file);
            touch($file, now()->timestamp - 50);
            $url = route('sales.recent', $this->session);
            $this->withCredentials()->withCookie($session->getName(), $session->getId())
                ->getJson($url)->assertOk()->assertCookieMissing($session->getName());
            clearstatcache(true, $file);
            $this->assertSame(now()->timestamp - 50, filemtime($file));
            $this->assertSame($original, file_get_contents($file));

            // The next HTTP request has a fresh session store in the web runtime.
            app(SessionManager::class)->forgetDrivers();
            app()->forgetInstance('session.store');
            app('auth')->forgetGuards();
            $this->travel(20)->seconds();
            $this->getJson($url)->assertUnauthorized()->assertJsonMissingPath('collected');
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function test_new_sale_is_visible_to_other_cashiers_and_on_return_from_confirmation(): void
    {
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $colleague = $this->createSalesActor();
        $this->attachPermission($colleague->rol_id, $this->salesPermissionId());
        $url = route('sales.recent', $this->session);
        $this->actingAs($colleague)->get(route('sales.create'))->assertSee('Todavía no hay ventas completadas');
        $this->actingAs($this->cashier)->post(route('sales.store'), [
            'command_uuid' => (string) Str::uuid(), 'cash_session_id' => $this->session,
            'lines' => [['article_id' => $this->article, 'quantity' => '1.000']],
            'payments' => [['payment_method_id' => $cash, 'amount' => '800.00', 'received_amount' => '1000.00', 'payment_uuid' => (string) Str::uuid()]],
        ])->assertRedirect(route('sales.success'));
        $this->get(route('sales.success'))->assertOk()->assertSee('Venta realizada')->assertSee('N.º 1')
            ->assertSee('06/11/2026')->assertSee('Efectivo')->assertSee('$800')->assertSee('$200')->assertSee('Nueva venta')->assertSee('Volver al inicio');
        $this->get(route('sales.create'))->assertOk()->assertSee('N.º 1')->assertSee('Articulo de venta')
            ->assertSeeInOrder(['Total cobrado en efectivo', '$800', 'Total cobrado por medios digitales', '$0']);
        $html = $this->actingAs($colleague)->getJson($url)->assertOk()
            ->assertJsonPath('collected.cash', '$800')->assertJsonPath('collected.digital', '$0')->json('html');
        $this->assertStringContainsString('N.º 1', $html);
        $this->assertStringContainsString('Efectivo', $html);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('pagos_venta', 1);
    }

    private function recordSale(User $actor, int $number, array $overrides = []): int
    {
        return $this->createSale($actor->id, $this->session, (string) Str::uuid(), [
            'fecha_comercial' => '2026-11-06', 'completada_en' => now('UTC'), 'numero_diario' => $number, ...$overrides,
        ]);
    }

    private function addPayment(int $sale, int $method, string $amount, array $overrides = []): void
    {
        $this->createSalePayment($sale, $method, $this->session, $this->cashier->id, (string) Str::uuid(), [
            'monto' => $amount, 'ocurrido_en' => now('UTC'), ...$overrides,
        ]);
    }

    private function salesPermissionId(): int
    {
        return (int) DB::table('permisos')->where('codigo', PermissionCode::SalesCreate->value)->value('id');
    }
}
