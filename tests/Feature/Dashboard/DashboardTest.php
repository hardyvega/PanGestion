<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Data\Dashboard\DashboardMetrics;
use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private User $user;

    private int $cashSessionId;

    private int $cashPaymentMethodId;

    private int $debitPaymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-16T15:00:00+00:00'));

        $roleId = $this->createRole();
        $permissionId = $this->createPermission();
        $this->attachPermission($roleId, $permissionId);
        $this->user = User::query()->findOrFail($this->createUser($roleId));

        $cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($cashRegisterId, $this->user->getKey());
        $this->cashPaymentMethodId = $this->createPaymentMethod([
            'codigo' => 'efectivo',
            'nombre' => 'Efectivo',
            'afecta_efectivo' => true,
            'orden_presentacion' => 1,
        ]);
        $this->debitPaymentMethodId = $this->createPaymentMethod([
            'codigo' => 'debito',
            'nombre' => 'Débito',
            'afecta_efectivo' => false,
            'orden_presentacion' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/inicio')->assertRedirect('/login');
    }

    public function test_dashboard_changes_day_week_and_month_together_at_santiago_midnight(): void
    {
        $this->grantCashHistory($this->user->rol_id);
        $this->actingAs($this->user);
        $fixtures = [
            [21, '2027-01-31', '2027-02-01 02:59:59+00', '130.00', $this->cashPaymentMethodId],
            [22, '2027-02-01', '2027-02-01 03:00:00+00', '240.00', $this->debitPaymentMethodId],
        ];
        foreach ($fixtures as [$id, $day, $instant, $amount, $method]) {
            $sale = $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid($id), [
                'fecha_comercial' => $day, 'numero_diario' => 1, 'total' => $amount, 'completada_en' => $instant,
            ]);
            $this->createSalePayment($sale, $method, $this->cashSessionId, $this->user->getKey(), $this->uuid($id + 100), [
                'monto' => $amount, 'ocurrido_en' => $instant,
            ]);
        }

        $this->travelTo(CarbonImmutable::parse('2027-02-01 02:59:59 UTC'));
        $this->get('/inicio?period=mes')->assertOk()->assertSee('31/01/2027')
            ->assertViewHas('metrics', fn ($metrics) => $metrics->salesToday === '130.00'
                && $metrics->salesWeek === '130.00' && $metrics->salesMonth === '130.00'
                && $metrics->cashToday === '130.00' && $metrics->debitToday === '0.00');
        $this->travelTo(CarbonImmutable::parse('2027-02-01 03:00:00 UTC'));
        $this->get('/inicio?period=semana')->assertOk()->assertSee('01/02/2027')
            ->assertViewHas('metrics', fn ($metrics) => $metrics->salesToday === '240.00'
                && $metrics->salesWeek === '240.00' && $metrics->salesMonth === '240.00'
                && $metrics->cashToday === '0.00' && $metrics->debitToday === '240.00');
    }

    public function test_dashboard_returns_exact_sales_and_payment_metrics(): void
    {
        $this->grantCashHistory($this->user->rol_id);

        $todaySale = $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(1), [
            'fecha_comercial' => '2026-10-16',
            'numero_diario' => 1,
            'total' => '100.00',
            'completada_en' => '2026-10-16 12:00:00-03',
        ]);
        $secondTodaySale = $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(2), [
            'fecha_comercial' => '2026-10-16',
            'numero_diario' => 2,
            'total' => '50.00',
            'completada_en' => '2026-10-16 13:00:00-03',
        ]);
        $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(3), [
            'fecha_comercial' => '2026-10-12',
            'numero_diario' => 1,
            'total' => '25.00',
            'completada_en' => '2026-10-12 12:00:00-03',
        ]);
        $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(4), [
            'fecha_comercial' => '2026-10-01',
            'numero_diario' => 1,
            'total' => '40.00',
            'completada_en' => '2026-10-01 12:00:00-03',
        ]);
        $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(5), [
            'fecha_comercial' => '2026-10-16',
            'numero_diario' => 3,
            'estado' => 'anulada',
            'total' => '999.00',
            'completada_en' => '2026-10-16 14:00:00-03',
            'anulada_en' => '2026-10-16 14:30:00-03',
            'usuario_anulador_id' => $this->user->getKey(),
            'motivo_anulacion' => 'Corrección de prueba',
        ]);
        $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(6), [
            'fecha_comercial' => '2026-09-30',
            'numero_diario' => 1,
            'total' => '500.00',
            'completada_en' => '2026-09-30 12:00:00-03',
        ]);

        $this->createSalePayment($todaySale, $this->cashPaymentMethodId, $this->cashSessionId, $this->user->getKey(), $this->uuid(11), [
            'monto' => '60.00',
            'ocurrido_en' => '2026-10-16 12:10:00-03',
        ]);
        $this->createSalePayment($todaySale, $this->debitPaymentMethodId, $this->cashSessionId, $this->user->getKey(), $this->uuid(12), [
            'monto' => '40.00',
            'ocurrido_en' => '2026-10-16 12:10:00-03',
        ]);
        $this->createSalePayment($secondTodaySale, $this->cashPaymentMethodId, $this->cashSessionId, $this->user->getKey(), $this->uuid(13), [
            'monto' => '50.00',
            'ocurrido_en' => '2026-10-16 13:10:00-03',
        ]);
        $this->createSalePayment($secondTodaySale, $this->cashPaymentMethodId, $this->cashSessionId, $this->user->getKey(), $this->uuid(14), [
            'tipo' => 'devolucion',
            'monto' => '10.00',
            'ocurrido_en' => '2026-10-16 14:10:00-03',
        ]);

        $metrics = app(DashboardService::class)->summary($this->user);

        $this->assertTrue($metrics->canViewFinancials);
        $this->assertSame('150.00', $metrics->salesToday);
        $this->assertSame(2, $metrics->salesTodayCount);
        $this->assertSame('100.00', $metrics->cashToday);
        $this->assertSame('40.00', $metrics->debitToday);
        $this->assertSame('175.00', $metrics->salesWeek);
        $this->assertSame('215.00', $metrics->salesMonth);

        foreach (['hoy', 'semana', 'mes'] as $period) {
            $this->actingAs($this->user)->get('/inicio?period='.$period)
                ->assertOk()
                ->assertViewHas('selectedPeriod', $period)
                ->assertSeeInOrder(['Hoy', 'Esta semana', 'Este mes'])
                ->assertSeeTextInOrder([
                    'Ventas de hoy', '$150', 'Efectivo de hoy', '$100', 'Débito de hoy', '$40',
                    'Ventas de esta semana', '$175', 'Ventas de este mes', '$215',
                ]);
        }
    }

    public function test_daily_access_does_not_query_or_expose_other_periods_even_if_requested(): void
    {
        $sale = $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(31), [
            'fecha_comercial' => '2026-10-16',
            'numero_diario' => 1,
            'total' => '150.00',
            'completada_en' => '2026-10-16 12:00:00-03',
        ]);
        $this->createSalePayment($sale, $this->cashPaymentMethodId, $this->cashSessionId, $this->user->getKey(), $this->uuid(32), [
            'monto' => '100.00',
            'ocurrido_en' => '2026-10-16 12:10:00-03',
        ]);
        $this->createSalePayment($sale, $this->debitPaymentMethodId, $this->cashSessionId, $this->user->getKey(), $this->uuid(33), [
            'monto' => '50.00',
            'ocurrido_en' => '2026-10-16 12:10:00-03',
        ]);
        foreach ([34 => ['2026-10-12', '913.00'], 35 => ['2026-10-01', '824.00']] as $id => [$date, $amount]) {
            $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid($id), [
                'fecha_comercial' => $date,
                'numero_diario' => 1,
                'total' => $amount,
                'completada_en' => $date.' 12:00:00-03',
            ]);
        }

        $this->actingAs($this->user);

        foreach (['text/html', 'application/json'] as $accept) {
            foreach (['', '?period=semana', '?period=mes'] as $query) {
                DB::enableQueryLog();
                try {
                    $response = $this->get('/inicio'.$query, ['Accept' => $accept]);
                    $salesQueries = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_contains($entry['query'], 'sales_amount'));
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }

                $response->assertOk()
                    ->assertViewHas('selectedPeriod', 'hoy')
                    ->assertViewHas('metrics', fn (DashboardMetrics $metrics): bool => $metrics->salesWeek === null && $metrics->salesMonth === null)
                    ->assertSeeTextInOrder(['Ventas de hoy', '$150', 'Efectivo de hoy', '$100', 'Débito de hoy', '$50'])
                    ->assertDontSee('Ventas de esta semana')
                    ->assertDontSee('Ventas de este mes')
                    ->assertDontSee('Esta semana')
                    ->assertDontSee('Este mes')
                    ->assertDontSee('period=semana')
                    ->assertDontSee('period=mes')
                    ->assertDontSee('1.063')
                    ->assertDontSee('1.887');

                $this->assertCount(1, $salesQueries);
                $this->assertSame(['2026-10-16', '2026-10-16'], array_slice($salesQueries->first()['bindings'], -2));
            }
        }
    }

    public function test_derived_order_payments_are_counted_once_from_order_history(): void
    {
        $clientId = (int) DB::table('clientes')->insertGetId(['nombre' => 'Cliente dashboard']);
        $orderId = (int) DB::table('pedidos')->insertGetId([
            'cliente_id' => $clientId,
            'usuario_creador_id' => $this->user->getKey(),
            'estado' => 'entregado',
            'entregado_en' => '2026-10-16 11:00:00-03',
        ]);
        $saleId = $this->createSale($this->user->getKey(), $this->cashSessionId, $this->uuid(20), [
            'fecha_comercial' => '2026-10-16',
            'numero_diario' => 1,
            'cliente_id' => $clientId,
            'pedido_id' => $orderId,
            'total' => '80.00',
            'monto_anticipo_aplicado' => '30.00',
            'completada_en' => '2026-10-16 11:00:00-03',
        ]);

        DB::table('pagos_pedido')->insert([
            [
                'pedido_id' => $orderId,
                'metodo_pago_id' => $this->cashPaymentMethodId,
                'sesion_caja_id' => $this->cashSessionId,
                'usuario_id' => $this->user->getKey(),
                'clave_idempotencia' => $this->uuid(21),
                'tipo' => 'cobro',
                'monto' => '30.00',
                'ocurrido_en' => '2026-10-16 10:00:00-03',
            ],
            [
                'pedido_id' => $orderId,
                'metodo_pago_id' => $this->debitPaymentMethodId,
                'sesion_caja_id' => $this->cashSessionId,
                'usuario_id' => $this->user->getKey(),
                'clave_idempotencia' => $this->uuid(22),
                'tipo' => 'cobro',
                'monto' => '50.00',
                'ocurrido_en' => '2026-10-16 11:00:00-03',
            ],
        ]);

        $metrics = app(DashboardService::class)->summary($this->user);

        $this->assertSame(1, DB::table('ventas')->where('id', $saleId)->count());
        $this->assertSame('80.00', $metrics->salesToday);
        $this->assertSame('30.00', $metrics->cashToday);
        $this->assertSame('50.00', $metrics->debitToday);
    }

    public function test_zero_sales_keep_dashboard_values_exact_and_visible(): void
    {
        $metrics = app(DashboardService::class)->summary($this->user);

        $this->assertSame('0.00', $metrics->salesToday);
        $this->assertSame(0, $metrics->salesTodayCount);
        $this->assertSame('0.00', $metrics->cashToday);
        $this->assertSame('0.00', $metrics->debitToday);
        $this->assertNull($metrics->salesWeek);
        $this->assertNull($metrics->salesMonth);

        $this->actingAs($this->user)
            ->get('/inicio')
            ->assertSee('$0')
            ->assertSee('0 ventas completadas');
    }

    public function test_navigation_expands_only_from_effective_permissions(): void
    {
        $roleId = $this->user->rol_id;
        $ordersPermission = $this->createPermission([
            'codigo' => 'pedidos.gestionar',
            'nombre' => 'Gestionar pedidos',
            'modulo' => 'pedidos',
        ]);
        $inventoryPermission = $this->createPermission([
            'codigo' => 'inventario.movimientos.ver',
            'nombre' => 'Ver movimientos',
            'modulo' => 'inventario',
        ]);
        $this->attachPermission($roleId, $ordersPermission);
        $this->attachPermission($roleId, $inventoryPermission);

        $this->actingAs($this->user)
            ->get('/inicio')
            ->assertSee('Pedidos')
            ->assertSee('Inventario')
            ->assertSee('/inventario')
            ->assertDontSee('Próximo');
    }

    public function test_dashboard_hides_financials_and_sales_action_without_sales_permission(): void
    {
        $roleId = $this->createRole(['codigo' => 'solo_consulta', 'nombre' => 'Administrador']);
        $this->grantCashHistory($roleId);
        $user = User::query()->findOrFail($this->createUser($roleId, [
            'nombre' => 'Sin permiso',
            'nombre_usuario' => 'sin_permiso',
        ]));

        $this->actingAs($user);

        $response = $this->get('/inicio');

        $response->assertOk()
            ->assertSee('Resumen disponible sin importes')
            ->assertDontSee('Nueva venta')
            ->assertDontSee('Ventas de esta semana')
            ->assertDontSee('class="metric-value"', false);
    }

    public function test_role_name_alone_does_not_grant_dashboard_financials_or_actions(): void
    {
        $roleId = $this->createRole(['codigo' => 'jefatura', 'nombre' => 'Administrador']);
        $user = User::query()->findOrFail($this->createUser($roleId, [
            'nombre' => 'Jefatura sin permisos',
            'nombre_usuario' => 'jefatura_sin_permisos',
        ]));

        $metrics = app(DashboardService::class)->summary($user);

        $this->assertFalse($metrics->canViewFinancials);
        $this->actingAs($user)->get('/inicio')->assertDontSee('Nueva venta');
    }

    public function test_authenticated_structure_has_sidebar_offcanvas_and_accessible_logout(): void
    {
        $response = $this->actingAs($this->user)->get('/inicio');

        $response->assertOk()
            ->assertSee('<title>Inicio | Almapan</title>', false)
            ->assertSee('<h1 id="dashboard-title">Bienvenido</h1>', false)
            ->assertSee('app-sidebar')
            ->assertSee('data-nav-toggle', false)
            ->assertSee('data-nav-scrim', false)
            ->assertSee('aria-label="Navegación principal"', false)
            ->assertSee('Cerrar sesión')
            ->assertDontSee('fonts.googleapis.com')
            ->assertDontSee('cdn.jsdelivr.net');
    }

    public function test_new_sale_is_protected_by_the_same_permission(): void
    {
        $this->actingAs($this->user)
            ->get('/ventas/nueva')
            ->assertOk()
            ->assertSee('<h1 id="sales-title">Nueva venta</h1>', false);

        $roleId = $this->createRole(['codigo' => 'sin_ventas', 'nombre' => 'Sin ventas']);
        $user = User::query()->findOrFail($this->createUser($roleId, [
            'nombre_usuario' => 'sin_ventas',
        ]));

        $this->actingAs($user)->get('/ventas/nueva')->assertForbidden();
    }

    public function test_money_formatter_keeps_exact_clp_without_float_conversion(): void
    {
        $this->assertSame('$125.000', Money::clp('125000.00'));
        $this->assertSame('-$1.250', Money::clp('-1250.00'));
    }

    private function grantCashHistory(int $roleId): void
    {
        $this->attachPermission($roleId, $this->createPermission([
            'codigo' => PermissionCode::CashSessionsView->value,
            'nombre' => 'Ver sesiones de caja',
            'modulo' => 'caja',
        ]));
    }

    private function uuid(int $number): string
    {
        return sprintf('f2%030d', $number);
    }
}
