<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Enums\PermissionCode as P;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Cash\CashFundService;
use App\Services\Cash\CashService;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Database\Seeders\PermisosFondosCajaSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\TestCase;

final class DailyCashWorkflowTest extends TestCase
{
    use DailyCashFixtures;
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private int $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00:00 UTC'));
        $this->owner = $this->dailyActor([P::CashFundsAuthorize, P::CashSessionsClose, P::CashSessionsView, P::CashManualMovementsManage, P::CashMovementsView]);
        $this->cashier = $this->dailyActor([P::CashSessionsOpen, P::CashManualMovementsManage, P::CashMovementsView]);
        $this->register = $this->dailyRegister();
    }

    public function test_initial_fund_is_explicit_authorized_and_has_no_cash_effect(): void
    {
        $fund = $this->dailyFund($this->register, '0', $this->owner);
        $this->assertSame('0.00', (string) $fund->monto);
        $this->assertSame($this->owner->id, (int) $fund->usuario_autorizante_id);
        $this->assertSame('inicial', $fund->origen);
        $this->assertNull($fund->utilizada_en);
        $this->assertSame(0, DB::table('sesiones_caja')->count());
        $this->assertSame(0, DB::table('movimientos_caja')->count());
        $this->assertSame(0, DB::table('cierres_caja')->count());
    }

    public function test_cashier_cannot_authorize_funds_or_close(): void
    {
        $this->actingAs($this->cashier)->get(route('cash.funds.create'))->assertForbidden();
        $this->post(route('cash.funds.store'), [])->assertForbidden();
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->post(route('cash.sessions.close', $session), [])->assertForbidden();
        $this->assertNull($session->fresh()->cerrada_en);
    }

    public function test_cashier_opens_with_server_snapshot_without_history_access(): void
    {
        $fund = $this->dailyFund($this->register, '50000', $this->owner);
        $this->actingAs($this->cashier)->get(route('cash.index'))->assertOk()->assertSee('Fondo autorizado:')->assertSee('$50.000')->assertDontSee('name="monto_inicial"', false);
        $this->post(route('cash.sessions.store'), $this->openingPayload($fund->id))->assertSessionHasNoErrors();
        $session = SesionCaja::query()->sole();
        $this->assertSame('50000.00', (string) $session->monto_inicial);
        $this->assertSame($this->cashier->id, $session->usuario_apertura_id);
        $this->assertNotNull($fund->fresh()->utilizada_en);
        $this->get(route('cash.index'))->assertOk()->assertSee('Registrar movimiento manual')->assertDontSee('Historial de sesiones')->assertDontSee('Confirmar cierre definitivo');
    }

    public function test_http_amount_manipulation_is_rejected_not_ignored_as_authority(): void
    {
        $fund = $this->dailyFund($this->register, '50000', $this->owner);
        $this->actingAs($this->cashier)->post(route('cash.sessions.store'), [...$this->openingPayload($fund->id), 'monto_inicial' => '900000'])->assertSessionHasErrors('monto_inicial');
        $this->assertSame(0, DB::table('sesiones_caja')->count());
        $this->assertNull($fund->fresh()->utilizada_en);
    }

    public function test_open_without_fund_is_denied(): void
    {
        $this->actingAs($this->cashier)->post(route('cash.sessions.store'), $this->openingPayload(999999))->assertSessionHasErrors('fondo_autorizado_id');
        $this->assertSame(0, DB::table('sesiones_caja')->count());
    }

    public function test_future_fund_cannot_be_used_today(): void
    {
        $fund = $this->dailyFund($this->register, '50000', $this->owner, '2026-10-20');
        $this->expectException(InventoryDomainException::class);
        app(CashService::class)->open($this->cashier, $this->register, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
    }

    public function test_expired_authorization_cannot_be_carried_forward(): void
    {
        $fund = $this->dailyFund($this->register, '50000', $this->owner);
        $this->travel(1)->days();
        $this->expectException(InventoryDomainException::class);
        app(CashService::class)->open($this->cashier, $this->register, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
    }

    public function test_fund_is_consumed_once_and_same_open_command_is_idempotent(): void
    {
        $fund = $this->dailyFund($this->register, '0', $this->owner);
        $uuid = (string) Str::uuid();
        $cash = app(CashService::class);
        $first = $cash->open($this->cashier, $this->register, (int) $fund->id, CommercialDate::today(), $uuid);
        $retry = $cash->open($this->cashier, $this->register, (int) $fund->id, CommercialDate::today(), $uuid);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, DB::table('sesiones_caja')->count());
        $this->expectException(InventoryDomainException::class);
        $cash->open($this->cashier, $this->register, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
    }

    public function test_different_cashiers_share_session_and_keep_their_responsibility(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $other = $this->dailyActor([P::CashManualMovementsManage]);
        $movement = app(CashService::class)->registerManualMovement($other, (int) $session->id, 'ingreso', '1000', 'Ingreso autorizado');
        $this->assertSame($other->id, (int) $movement->usuario_id);
        $this->assertSame($this->cashier->id, $session->usuario_apertura_id);
        $this->assertSame(1, DB::table('sesiones_caja')->count());
    }

    public function test_closure_preserves_pre_withdrawal_balance_and_authorizes_only_next_fund(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $closed = $this->dailyClose($this->owner, $session);
        $closing = $closed->cierre;
        $this->assertSame('140000.00', (string) $closing->esperado_antes_retiro);
        $this->assertSame('140000.00', (string) $closing->contado_antes_retiro);
        $this->assertSame('0.00', (string) $closing->diferencia);
        $this->assertSame('90000.00', (string) $closing->retiro_final);
        $this->assertSame('50000.00', (string) $closing->fondo_remanente);
        $this->assertSame($this->owner->id, (int) $closing->usuario_confirmador_id);
        $this->assertSame('140000.00', (string) $closed->monto_teorico_cierre);
        $this->assertSame(1, DB::table('movimientos_caja')->where('cierre_caja_id', $closing->id)->count());
        $this->assertSame('50000.00', (string) $closing->fondoSiguiente->monto);
        $this->assertSame('2026-10-20', $closing->fondoSiguiente->fecha_operativa->format('Y-m-d'));
        $this->assertSame('140000.00', app(CashService::class)->overview($this->owner, (int) $session->id)['theoretical']);
    }

    public function test_shortage_requires_observation_and_keeps_negative_sign(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $closed = $this->dailyClose($this->owner, $session, '139000', '50000', 'Faltan mil al contar');
        $this->assertSame('-1000.00', (string) $closed->cierre->diferencia);
        $this->assertSame('89000.00', (string) $closed->cierre->retiro_final);
        $this->actingAs($this->owner)->get(route('cash.index', ['session' => $session->id]))->assertOk()->assertSee('FALTANTE DE CAJA')->assertSee('Faltan mil al contar');
    }

    public function test_surplus_requires_observation_and_is_highlighted_in_history(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $closed = $this->dailyClose($this->owner, $session, '141000', '50000', 'Sobrante contado');
        $this->assertSame('1000.00', (string) $closed->cierre->diferencia);
        $this->actingAs($this->owner)->get(route('cash.index', ['session' => $session->id]))->assertOk()->assertSee('SOBRANTE DE CAJA')->assertSee('SOBRANTE ');
    }

    public function test_difference_without_observation_has_no_partial_effect(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        try {
            $this->dailyClose($this->owner, $session, '139000');
            $this->fail('Se aceptó faltante sin explicar');
        } catch (InventoryDomainException $e) {
        }
        $this->assertNull($session->fresh()->cerrada_en);
        $this->assertSame(0, DB::table('cierres_caja')->count());
        $this->assertSame(0, DB::table('movimientos_caja')->count());
        $this->assertSame(1, DB::table('fondos_caja_autorizados')->count());
    }

    public function test_next_fund_above_counted_cash_is_rejected(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->expectException(\InvalidArgumentException::class);
        $this->dailyClose($this->owner, $session, '140000', '140001');
    }

    public function test_zero_final_withdrawal_creates_no_money_movement(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $closed = $this->dailyClose($this->owner, $session, '140000', '140000');
        $this->assertSame('0.00', (string) $closed->cierre->retiro_final);
        $this->assertSame(0, DB::table('movimientos_caja')->count());
    }

    public function test_final_withdrawal_does_not_duplicate_previous_manual_withdrawals(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        app(CashService::class)->registerManualMovement($this->cashier, (int) $session->id, 'retiro', '20000', 'Retiro previo real');
        $closed = $this->dailyClose($this->owner, $session, '120000', '50000');
        $this->assertSame('120000.00', (string) $closed->cierre->esperado_antes_retiro);
        $this->assertSame('70000.00', (string) $closed->cierre->retiro_final);
        $this->assertSame('20000.00', $closed->cierre->resumen['totals']['withdrawals']);
        $this->assertSame('90000.00', DB::table('movimientos_caja')->sum('monto'));
    }

    public function test_same_close_confirmation_is_idempotent(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $uuid = (string) Str::uuid();
        $first = $this->dailyClose($this->owner, $session, uuid: $uuid);
        $retry = $this->dailyClose($this->owner, $session, uuid: $uuid);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, DB::table('cierres_caja')->count());
        $this->assertSame(1, DB::table('movimientos_caja')->count());
        $this->assertSame(2, DB::table('fondos_caja_autorizados')->count());
    }

    public function test_failed_closure_rolls_back_snapshot_withdrawal_and_next_fund(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->travel(1)->days();
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert into "fondos_caja_autorizados"')) {
                $armed = false;
                throw new \RuntimeException('Injected failure');
            }
        });
        try {
            $this->dailyClose($this->owner, $session);
            $this->fail('No se inyectó fallo');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected failure', $e->getMessage());
        }
        $this->assertSame(0, DB::table('cierres_caja')->count());
        $this->assertSame(0, DB::table('movimientos_caja')->count());
        $this->assertSame(1, DB::table('fondos_caja_autorizados')->count());
        $this->assertNull($session->fresh()->cerrada_en);
    }

    public function test_snapshot_and_closed_balance_do_not_change_when_master_data_changes(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $closed = $this->dailyClose($this->owner, $session);
        $before = $closed->cierre->resumen;
        DB::table('usuarios')->where('id', $this->owner->id)->update(['nombre' => 'Nombre cambiado']);
        DB::table('cajas')->where('id', $this->register)->update(['nombre' => 'Otro nombre']);
        $view = app(CashService::class)->overview($this->owner, (int) $session->id);
        $this->assertSame($before, $view['summary']);
        $this->assertSame('140000.00', $view['theoretical']);
        $this->assertSame('0.00', $view['difference']);
    }

    public function test_database_rejects_editing_a_confirmed_closure(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $closed = $this->dailyClose($this->owner, $session);
        $this->expectException(QueryException::class);
        DB::table('cierres_caja')->where('id', $closed->cierre->id)->update(['observacion' => 'reescritura']);
    }

    public function test_new_movement_on_closed_session_is_rejected(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->dailyClose($this->owner, $session);
        $this->expectException(InventoryDomainException::class);
        app(CashService::class)->registerManualMovement($this->cashier, (int) $session->id, 'ingreso', '1', 'Tardío');
    }

    public function test_close_permission_without_fund_authority_is_not_sufficient(): void
    {
        $onlyClose = $this->dailyActor([P::CashSessionsClose]);
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->actingAs($onlyClose)->post(route('cash.sessions.close', $session), [])->assertForbidden();
        $this->assertSame(0, DB::table('cierres_caja')->count());
    }

    public function test_initial_fund_permission_is_idempotently_installed_without_role_assignment(): void
    {
        DB::table('permisos')->where('codigo', P::CashFundsAuthorize->value)->update(['nombre' => 'Nombre personalizado', 'activo' => false]);
        $before = DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson();
        $this->seed(PermisosFondosCajaSeeder::class);
        $this->seed(PermisosFondosCajaSeeder::class);
        $this->assertSame(1, DB::table('permisos')->where('codigo', P::CashFundsAuthorize->value)->count());
        $this->assertSame('Nombre personalizado', DB::table('permisos')->where('codigo', P::CashFundsAuthorize->value)->value('nombre'));
        $this->assertFalse(DB::table('permisos')->where('codigo', P::CashFundsAuthorize->value)->value('activo'));
        $this->assertSame($before, DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson());
    }

    public function test_owner_can_recover_old_session_and_authorize_today_without_creating_phantom_activity(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register, '60000');
        $confirmation = CarbonImmutable::parse('2026-11-07 01:30:00 UTC');
        $this->travelTo($confirmation);
        $payload = [
            'monto_declarado' => '60000', 'fondo_siguiente' => '60000', 'fecha_siguiente' => '2026-11-06',
            'clave_idempotencia' => (string) Str::uuid(), 'confirmacion' => '1', 'esperado_revisado' => '60000',
        ];

        $this->actingAs($this->cashier)->get(route('cash.index', ['session' => $session->id]))->assertOk()
            ->assertSee('pendiente de revisión')->assertSee('dueño autorizado')
            ->assertDontSee('$60.000')->assertDontSee('Historial de sesiones')
            ->assertDontSee('Confirmar apertura')->assertDontSee('Confirmar cierre definitivo');
        $this->post(route('cash.sessions.close', $session), $payload)->assertForbidden();
        $this->post(route('cash.movements.store', $session), ['tipo_movimiento' => 'ingreso', 'monto' => '1', 'concepto' => 'Rechazado'])
            ->assertSessionHasErrors('cash');

        $this->actingAs($this->owner)->get(route('cash.index'))->assertOk()
            ->assertSee('Cierre tardío')->assertSee('min="2026-11-06" value=""', false)
            ->assertDontSee('Registrar movimiento manual');
        $this->post(route('cash.sessions.close', $session), $payload)->assertSessionHasNoErrors();
        $closed = $session->fresh();
        $this->assertSame('2026-10-19', $closed->fecha_operativa->format('Y-m-d'));
        $this->assertSame('2026-10-19', $closed->cierre->fecha_operativa->format('Y-m-d'));
        $this->assertTrue($session->abierta_en->equalTo($closed->abierta_en));
        $this->assertSame($session->usuario_apertura_id, $closed->usuario_apertura_id);
        $this->assertSame($session->fondo_autorizado_id, $closed->fondo_autorizado_id);
        $this->assertTrue($confirmation->equalTo($closed->cerrada_en));
        $this->assertTrue($confirmation->equalTo($closed->cierre->confirmado_en));
        $this->assertSame($this->owner->id, $closed->cierre->usuario_confirmador_id);
        $this->assertSame('60000.00', (string) $closed->cierre->esperado_antes_retiro);
        $this->assertSame('0.00', (string) $closed->cierre->retiro_final);
        $fund = $closed->cierre->fondoSiguiente;
        $this->assertSame('2026-11-06', $fund->fecha_operativa->format('Y-m-d'));

        $this->actingAs($this->cashier)->post(route('cash.sessions.store'), $this->openingPayload($fund->id))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sesiones_caja', ['fondo_autorizado_id' => $fund->id, 'fecha_operativa' => '2026-11-06', 'monto_inicial' => '60000.00']);
        $this->travel(1)->days();
        $this->actingAs($this->owner)->post(route('cash.sessions.close', $session), $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('cierres_caja', 1);
        $this->assertDatabaseCount('fondos_caja_autorizados', 2);
        $this->assertDatabaseCount('sesiones_caja', 2);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('movimientos_caja', 0);
    }

    #[DataProvider('lateCountDifferences')]
    public function test_late_close_with_difference_requires_observation_and_preserves_the_count(string $count, string $difference, string $withdrawal): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register, '60000');
        $this->travel(3)->days();
        $uuid = (string) Str::uuid();
        $payload = [
            'monto_declarado' => $count, 'fondo_siguiente' => '50000', 'fecha_siguiente' => '2026-10-25',
            'clave_idempotencia' => $uuid, 'confirmacion' => '1', 'esperado_revisado' => '60000',
        ];
        $this->actingAs($this->owner)->post(route('cash.sessions.close', $session), $payload)->assertSessionHasErrors('cash');
        $this->assertDatabaseCount('cierres_caja', 0);
        $payload['observacion'] = 'Cuadratura física revisada por el dueño.';
        $this->post(route('cash.sessions.close', $session), $payload)->assertSessionHasNoErrors();
        $this->post(route('cash.sessions.close', $session), $payload)->assertSessionHasNoErrors();
        $this->post(route('cash.sessions.close', $session), [...$payload, 'clave_idempotencia' => (string) Str::uuid()])->assertSessionHasErrors('cash');
        $closing = $session->fresh()->cierre;
        $this->assertSame($difference, (string) $closing->diferencia);
        $this->assertSame($count, (string) $closing->contado_antes_retiro);
        $this->assertSame($payload['observacion'], $closing->observacion);
        $this->assertSame('2026-10-25', $closing->fondoSiguiente->fecha_operativa->format('Y-m-d'));
        $this->assertDatabaseCount('cierres_caja', 1);
        $this->assertDatabaseCount('movimientos_caja', 1);
        $this->assertDatabaseHas('movimientos_caja', ['cierre_caja_id' => $closing->id, 'tipo_movimiento' => 'retiro', 'monto' => $withdrawal]);
    }

    public static function lateCountDifferences(): array
    {
        return ['faltante' => ['59000.00', '-1000.00', '9000.00'], 'sobrante' => ['61000.00', '1000.00', '11000.00']];
    }

    public function test_late_close_rejects_expired_and_duplicate_funds_and_blocks_incompatible_opening(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->travel(2)->days();
        $historical = SesionCaja::query()->forceCreate([
            'caja_id' => $this->register, 'usuario_apertura_id' => $this->cashier->id,
            'usuario_cierre_id' => $this->owner->id, 'monto_inicial' => '100', 'monto_cierre_declarado' => '100',
            'monto_teorico_cierre' => '100', 'abierta_en' => now('UTC')->subDays(4), 'cerrada_en' => now('UTC')->subDays(4)->addHour(),
        ]);
        $historicalClosing = DB::table('cierres_caja')->insertGetId([
            'sesion_caja_id' => $historical->id, 'usuario_confirmador_id' => $this->owner->id,
            'fecha_operativa' => '2026-10-17', 'fecha_siguiente' => CommercialDate::today(),
            'esperado_antes_retiro' => '100', 'contado_antes_retiro' => '100', 'diferencia' => '0',
            'retiro_final' => '0', 'fondo_remanente' => '100', 'resumen' => '{"version":1}',
            'confirmado_en' => $historical->cerrada_en, 'clave_idempotencia' => (string) Str::uuid(),
        ]);
        $fundId = DB::table('fondos_caja_autorizados')->insertGetId([
            'caja_id' => $this->register, 'fecha_operativa' => CommercialDate::today(), 'monto' => '100',
            'usuario_autorizante_id' => $this->owner->id, 'origen' => 'cierre', 'motivo' => 'Fondo de prueba',
            'cierre_origen_id' => $historicalClosing,
            'clave_idempotencia' => (string) Str::uuid(),
        ]);
        $this->actingAs($this->cashier)->get(route('cash.index'))->assertOk()
            ->assertSee('pendiente de revisión')->assertDontSee('Confirmar apertura');
        $this->post(route('cash.sessions.store'), $this->openingPayload($fundId))->assertSessionHasErrors('cash');
        $payload = [
            'monto_declarado' => '140000', 'fondo_siguiente' => '50000', 'fecha_siguiente' => '2026-10-20',
            'clave_idempotencia' => (string) Str::uuid(), 'confirmacion' => '1', 'esperado_revisado' => '140000',
        ];
        $this->actingAs($this->owner)->post(route('cash.sessions.close', $session), $payload)
            ->assertSessionHasErrors(['cash' => 'No se puede autorizar un fondo para una fecha vencida.']);
        $this->post(route('cash.sessions.close', $session), [...$payload, 'fecha_siguiente' => CommercialDate::today()])
            ->assertSessionHasErrors(['cash' => 'La fecha siguiente ya tiene un fondo autorizado. No se reemplazará.']);
        $this->assertDatabaseCount('cierres_caja', 1);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->assertDatabaseCount('sesiones_caja', 2);
        $this->assertNull($session->fresh()->cerrada_en);
        $this->assertDatabaseHas('fondos_caja_autorizados', ['id' => $fundId, 'utilizada_en' => null]);
    }

    public function test_legacy_session_cannot_be_closed_or_receive_manual_movements(): void
    {
        $session = SesionCaja::query()->forceCreate([
            'caja_id' => $this->register, 'usuario_apertura_id' => $this->cashier->id, 'monto_inicial' => '60000',
            'abierta_en' => now('UTC')->subDay(),
        ]);
        $this->actingAs($this->owner)->get(route('cash.index'))->assertOk()
            ->assertSee('Esta sesión no tiene fecha operativa')->assertDontSee('Confirmar cierre definitivo');
        $this->post(route('cash.sessions.close', $session), [
            'monto_declarado' => '60000', 'fondo_siguiente' => '60000', 'fecha_siguiente' => CommercialDate::today(),
            'clave_idempotencia' => (string) Str::uuid(), 'confirmacion' => '1', 'esperado_revisado' => '60000',
        ])->assertSessionHasErrors('cash');
        $this->post(route('cash.movements.store', $session), ['tipo_movimiento' => 'ingreso', 'monto' => '1', 'concepto' => 'Rechazado'])
            ->assertSessionHasErrors('cash');
        $this->assertDatabaseCount('cierres_caja', 0);
        $this->assertDatabaseCount('fondos_caja_autorizados', 0);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->assertDatabaseHas('sesiones_caja', ['id' => $session->id, 'fecha_operativa' => null, 'cerrada_en' => null]);
    }

    public function test_changed_balance_requires_review_again(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        app(CashService::class)->registerManualMovement($this->cashier, (int) $session->id, 'ingreso', '1', 'Venta mientras revisa');
        $this->expectException(InventoryDomainException::class);
        app(CashService::class)->close($this->owner, (int) $session->id, '140001', '50000', '2026-10-20', (string) Str::uuid(), true, null, '140000');
    }

    public function test_next_day_open_consumes_exactly_the_fund_from_the_previous_closure(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->dailyClose($this->owner, $session);
        $this->travel(1)->days();
        $fund = app(CashFundService::class)->available($this->register);
        $opened = app(CashService::class)->open($this->cashier, $this->register, (int) $fund->id, CommercialDate::today(), (string) Str::uuid());
        $this->assertSame('50000.00', (string) $opened->monto_inicial);
        $this->assertSame(2, DB::table('sesiones_caja')->count());
        $this->assertSame(1, DB::table('movimientos_caja')->count());
    }

    private function openingPayload(int $fundId): array
    {
        return ['caja_id' => $this->register, 'fondo_autorizado_id' => $fundId, 'fecha_operativa' => CommercialDate::today(), 'clave_idempotencia' => (string) Str::uuid()];
    }

    public function test_initial_fund_web_form_requires_owner_confirmation_and_preserves_zero(): void
    {
        $this->actingAs($this->owner)->get(route('cash.funds.create'))->assertOk()->assertSee('Autorizar primer fondo');
        $payload = ['caja_id' => $this->register, 'fecha_operativa' => CommercialDate::today(), 'monto' => '0', 'motivo' => 'Inicio autorizado', 'clave_idempotencia' => (string) Str::uuid()];
        $this->post(route('cash.funds.store'), $payload)->assertSessionHasErrors('confirmacion');
        $this->assertSame(0, DB::table('fondos_caja_autorizados')->count());
        $this->post(route('cash.funds.store'), [...$payload, 'confirmacion' => '1'])->assertSessionHasNoErrors();
        $this->post(route('cash.funds.store'), [...$payload, 'confirmacion' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('fondos_caja_autorizados')->count());
        $this->assertSame('0.00', DB::table('fondos_caja_autorizados')->sole()->monto);
        $this->assertSame(0, DB::table('sesiones_caja')->count());
    }

    public function test_new_fund_permission_can_be_installed_from_missing_without_granting_any_role(): void
    {
        $permissionId = DB::table('permisos')->where('codigo', P::CashFundsAuthorize->value)->value('id');
        DB::table('rol_permiso')->where('permiso_id', $permissionId)->delete();
        DB::table('permisos')->where('id', $permissionId)->delete();
        $before = DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson();
        $this->seed(PermisosFondosCajaSeeder::class);
        $this->seed(PermisosFondosCajaSeeder::class);
        $this->assertSame(1, DB::table('permisos')->where('codigo', P::CashFundsAuthorize->value)->where('activo', true)->count());
        $this->assertSame($before, DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson());
    }

    public function test_owner_can_close_without_unrelated_history_permission(): void
    {
        $owner = $this->dailyActor([P::CashSessionsClose, P::CashFundsAuthorize]);
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->actingAs($owner)->get(route('cash.index'))->assertOk()->assertSee('Confirmar cierre definitivo')->assertDontSee('Historial de sesiones');
        $this->followingRedirects()->post(route('cash.sessions.close', $session), [
            'monto_declarado' => '140000', 'fondo_siguiente' => '50000', 'fecha_siguiente' => '2026-10-20',
            'clave_idempotencia' => (string) Str::uuid(), 'confirmacion' => '1', 'esperado_revisado' => '140000',
        ])->assertOk()->assertSee('Sesión de caja cerrada correctamente.');
        $this->assertNotNull($session->fresh()->cerrada_en);
        $this->get(route('cash.index', ['session' => $session->id]))->assertNotFound();
    }

    public function test_funds_permission_alone_cannot_close_or_open_a_session(): void
    {
        $owner = $this->dailyActor([P::CashFundsAuthorize]);
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->actingAs($owner)->post(route('cash.sessions.close', $session), [])->assertForbidden();
        $this->post(route('cash.sessions.store'), [])->assertForbidden();
        $this->assertNull($session->fresh()->cerrada_en);
    }

    #[DataProvider('immutableOperations')]
    public function test_database_blocks_rewriting_confirmed_cash_history(string $operation): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->dailyClose($this->owner, $session);
        $this->expectException(QueryException::class);
        match ($operation) {
            'delete_closing' => DB::table('cierres_caja')->delete(),
            'edit_fund' => DB::table('fondos_caja_autorizados')->where('id', $session->fondo_autorizado_id)->update(['monto' => '1']),
            'delete_fund' => DB::table('fondos_caja_autorizados')->delete(),
            'edit_session' => DB::table('sesiones_caja')->where('id', $session->id)->update(['monto_teorico_cierre' => '1']),
            'delete_movement' => DB::table('movimientos_caja')->where('sesion_caja_id', $session->id)->delete(),
            'edit_movement' => DB::table('movimientos_caja')->where('sesion_caja_id', $session->id)->update(['monto' => '1']),
            'late_movement' => DB::table('movimientos_caja')->insert(['sesion_caja_id' => $session->id, 'usuario_id' => $this->owner->id, 'tipo_movimiento' => 'ingreso', 'monto' => '1', 'concepto' => 'Imputación tardía']),
        };
    }

    public static function immutableOperations(): array
    {
        return array_map(fn (string $operation): array => [$operation], ['delete_closing', 'edit_fund', 'delete_fund', 'edit_session', 'delete_movement', 'edit_movement', 'late_movement']);
    }

    public function test_additive_migration_can_roll_back_and_reapply_without_old_schema_changes(): void
    {
        $migration = require database_path('migrations/2026_10_19_000044_add_authorized_cash_funds_and_daily_closings.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('cierres_caja'));
        $this->assertTrue(Schema::hasTable('sesiones_caja'));
        $migration->up();
        $session = $this->dailyOpen($this->cashier, $this->register);
        $this->assertSame('0.00', (string) $this->dailyClose($this->owner, $session)->cierre->diferencia);
    }

    public function test_final_withdrawal_is_causally_distinct_from_manual_withdrawals(): void
    {
        $session = $this->dailyOpen($this->cashier, $this->register);
        app(CashService::class)->registerManualMovement($this->cashier, (int) $session->id, 'retiro', '20000', 'Retiro previo');
        $this->dailyClose($this->owner, $session, '120000', '50000');
        $cash = app(CashService::class);
        $manual = $cash->movements($this->owner, ['origen' => 'manual']);
        $closing = $cash->movements($this->owner, ['origen' => 'cierre']);
        $this->assertSame(1, $manual->total());
        $this->assertSame(1, $closing->total());
        $this->assertSame('manual', $manual->items()[0]['source']);
        $this->assertSame('cierre', $closing->items()[0]['source']);
        $this->assertSame('70000.00', $closing->items()[0]['amount']);
        $this->assertSame((int) $session->fresh()->cierre->id, $closing->items()[0]['causal_id']);
    }
}
