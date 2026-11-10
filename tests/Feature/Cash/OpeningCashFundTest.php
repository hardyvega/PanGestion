<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Enums\PermissionCode as P;
use App\Models\FondoCajaAutorizado;
use App\Models\User;
use App\Services\Cash\CashService;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\TestCase;

final class OpeningCashFundTest extends TestCase
{
    use DailyCashFixtures;
    use RefreshDatabase;

    private const MIGRATION = '2026_11_08_000047_add_independent_cash_opening_funds';

    private User $owner;

    private User $cashier;

    private int $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00:00 UTC'));
        $this->owner = $this->dailyActor([P::CashFundsAuthorize, P::CashSessionsClose, P::CashSessionsView]);
        $this->cashier = $this->dailyActor([P::CashSessionsOpen]);
        $this->register = (int) DB::table('cajas')->insertGetId(['codigo' => 'principal', 'nombre' => 'Caja principal', 'activo' => true]);
    }

    public function test_owner_authorizes_today_after_an_unused_fund_and_cashier_consumes_the_new_fund_once(): void
    {
        $expired = $this->expireClosingFund();
        $before = $expired->getRawOriginal();
        $oldClosing = $expired->cierreOrigen->getRawOriginal();
        $url = route('cash.funds.opening.create');
        $this->actingAs($this->owner)->get(route('cash.index'))->assertOk()
            ->assertSee('Autorizar fondo de apertura')->assertDontSee('Autorizar primer fondo')
            ->assertSee('Vencido sin usar')->assertSee('Utilizado')->assertSee('Cierre diario');
        $this->get($url)->assertOk()->assertSee('08/11/2026')
            ->assertSee('Esta autorización no abre Caja ni registra un ingreso')
            ->assertSee('El fondo de una jornada anterior venció sin utilizarse');

        $this->actingAs($this->cashier)->get(route('cash.index'))->assertOk()
            ->assertDontSee('Autorizar fondo de apertura')->assertDontSee('Historial de fondos autorizados')
            ->assertSee('Caja principal todavía no tiene un fondo autorizado para hoy. Solicita la autorización a un responsable.');
        $this->get($url)->assertForbidden();
        $this->post(route('cash.funds.opening.store'), $this->authorization())->assertForbidden();
        $this->post(route('cash.sessions.store'), $this->opening($expired))->assertSessionHasErrors('cash');

        $data = $this->authorization();
        $this->actingAs($this->owner)->from($url)->post(route('cash.funds.opening.store'), $data)
            ->assertRedirect(route('cash.index'))->assertSessionHasNoErrors();
        $fund = FondoCajaAutorizado::query()->where('origen', 'apertura')->sole();
        $this->assertSame('60000.00', $fund->monto);
        $this->assertSame('2026-11-08', $fund->fecha_operativa->toDateString());
        $this->assertSame($this->owner->id, $fund->usuario_autorizante_id);
        $this->assertSame($data['motivo'], $fund->motivo);
        $this->assertSame('2026-11-08 22:00:00', $fund->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($fund->cierre_origen_id);
        $this->assertNull($fund->utilizada_en);
        $this->assertSame($before, $expired->fresh()->getRawOriginal());
        $this->assertSame($oldClosing, $expired->cierreOrigen->fresh()->getRawOriginal());
        $this->assertDatabaseCount('sesiones_caja', 1);
        $this->assertDatabaseCount('cierres_caja', 1);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->get(route('cash.index'))->assertOk()->assertSee('Disponible')->assertSee('Autorización de apertura')
            ->assertDontSee('Autorizar fondo de apertura');

        $opening = $this->opening($fund);
        $this->actingAs($this->cashier)->get(route('cash.index'))->assertOk()->assertSee('Confirmar apertura');
        $this->post(route('cash.sessions.store'), $opening)->assertSessionHasNoErrors();
        $this->post(route('cash.sessions.store'), $opening)->assertSessionHasNoErrors();
        $this->post(route('cash.sessions.store'), [...$opening, 'clave_idempotencia' => (string) Str::uuid()])
            ->assertSessionHasErrors('cash');
        $this->actingAs($this->owner)->post(route('cash.funds.opening.store'), $data)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Fondo de apertura autorizado para la jornada. La autorización no abre una sesión de Caja.');
        $this->assertNotNull($fund->fresh()->utilizada_en);
        $this->assertDatabaseHas('sesiones_caja', ['fondo_autorizado_id' => $fund->id, 'monto_inicial' => '60000.00']);
        $this->assertDatabaseCount('sesiones_caja', 2);
        $this->assertDatabaseCount('movimientos_caja', 0);
    }

    public function test_initial_setup_and_pre_migration_database_do_not_offer_independent_authorization(): void
    {
        $url = route('cash.funds.opening.store');
        $this->actingAs($this->owner)->get(route('cash.index'))->assertOk()
            ->assertSee('Autorizar primer fondo')->assertDontSee('Autorizar fondo de apertura');
        $this->post($url, $this->authorization())->assertSessionHasErrors([
            'cash' => 'Para una caja sin historial, utiliza Autorizar primer fondo.',
        ]);
        $this->expireClosingFund();
        $this->get(route('cash.funds.create'))->assertRedirect(route('cash.index'))->assertSessionHasErrors('cash');

        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $this->get(route('cash.index'))->assertOk()->assertDontSee('Autorizar fondo de apertura');
        $this->post($url, $this->authorization())->assertSessionHasErrors([
            'cash' => 'La autorización de apertura aún no está habilitada en este entorno.',
        ]);
        $this->assertDatabaseCount('fondos_caja_autorizados', 2);
    }

    public function test_zero_is_allowed_but_invalid_amounts_blank_reason_and_missing_confirmation_are_rejected(): void
    {
        $this->expireClosingFund();
        $this->actingAs($this->owner);
        $url = route('cash.funds.opening.store');
        $base = $this->authorization();
        foreach (['-1', '60.000', 'NaN', '1000000000000'] as $invalid) {
            $this->post($url, [...$base, 'monto' => $invalid])->assertSessionHasErrors('monto');
        }
        $this->post($url, [...$base, 'motivo' => ' '])->assertSessionHasErrors('motivo');
        $this->post($url, [...$base, 'confirmacion' => '0'])->assertSessionHasErrors('confirmacion');
        $this->assertDatabaseCount('fondos_caja_autorizados', 2);
        $this->post($url, [...$base, 'monto' => '0'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fondos_caja_autorizados', ['origen' => 'apertura', 'monto' => '0.00', 'utilizada_en' => null]);
    }

    public function test_same_confirmation_is_idempotent_and_a_second_authorization_cannot_replace_it(): void
    {
        $this->expireClosingFund();
        $url = route('cash.funds.opening.store');
        $this->actingAs($this->owner);
        $data = $this->authorization();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'monto' => '50000'])->assertSessionHasErrors('cash');
        $this->post($url, $this->authorization())->assertSessionHasErrors([
            'cash' => 'Caja principal ya tiene un fondo autorizado para esa jornada.',
        ]);
        $this->assertDatabaseCount('fondos_caja_autorizados', 3);
        $this->assertDatabaseHas('fondos_caja_autorizados', ['origen' => 'apertura', 'monto' => '60000.00']);
    }

    public function test_active_session_of_today_or_an_older_jornada_prevents_authorization(): void
    {
        $this->dailyOpen($this->cashier, $this->register);
        $this->actingAs($this->owner);
        foreach (['2026-11-08 22:00:00 UTC', '2026-11-09 03:00:00 UTC'] as $time) {
            $this->travelTo(CarbonImmutable::parse($time));
            $this->get(route('cash.index'))->assertOk()->assertDontSee('Autorizar fondo de apertura');
            $this->post(route('cash.funds.opening.store'), $this->authorization())->assertSessionHasErrors([
                'cash' => 'Caja principal ya tiene una sesión abierta. Si es de otra jornada, revisa el cierre pendiente.',
            ]);
        }
        $this->assertDatabaseCount('fondos_caja_autorizados', 1);
        $this->assertDatabaseCount('sesiones_caja', 1);
    }

    public function test_inactive_and_non_principal_registers_cannot_receive_opening_authorizations(): void
    {
        $this->expireClosingFund();
        $this->actingAs($this->owner);
        $url = route('cash.funds.opening.store');
        $this->post($url, [...$this->authorization(), 'caja_id' => $this->dailyRegister()])->assertSessionHasErrors('cash');
        DB::table('cajas')->where('id', $this->register)->update(['activo' => false]);
        $this->get(route('cash.index'))->assertOk()->assertDontSee('Autorizar fondo de apertura');
        $this->post($url, $this->authorization())->assertSessionHasErrors(['cash' => 'La caja no está activa.']);
        $this->assertDatabaseCount('fondos_caja_autorizados', 2);
    }

    #[DataProvider('santiagoMidnights')]
    public function test_opening_authorization_uses_the_santiago_boundary_and_rejects_a_stale_form(string $midnight, string $yesterday, string $today): void
    {
        $this->expireClosingFund('2026-01-10 19:00:00 UTC');
        $boundary = CarbonImmutable::parse($midnight);
        $this->travelTo($boundary->subSecond());
        $this->actingAs($this->owner)->get(route('cash.funds.opening.create'))->assertOk()
            ->assertSee('name="fecha_operativa" value="'.$yesterday.'"', false);
        $url = route('cash.funds.opening.store');
        $this->post($url, [...$this->authorization(), 'fecha_operativa' => $today])->assertSessionHasErrors('cash');
        $stale = $this->authorization();
        $this->travelTo($boundary);
        $this->post($url, $stale)->assertSessionHasErrors('cash');
        $this->post($url, $this->authorization())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fondos_caja_autorizados', ['fecha_operativa' => $today, 'origen' => 'apertura']);
        $this->assertDatabaseMissing('fondos_caja_autorizados', ['fecha_operativa' => $yesterday, 'origen' => 'apertura']);
    }

    public static function santiagoMidnights(): array
    {
        return [
            'summer' => ['2026-11-09 03:00:00 UTC', '2026-11-08', '2026-11-09'],
            'winter' => ['2026-07-15 04:00:00 UTC', '2026-07-14', '2026-07-15'],
        ];
    }

    public function test_migration_round_trip_preserves_initial_and_closing_funds_and_blocks_unsafe_rollback(): void
    {
        $expired = $this->expireClosingFund();
        $history = DB::table('fondos_caja_autorizados')->orderBy('id')->get();
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
        $this->assertStringNotContainsString("'apertura'", $this->originConstraint());
        $migration->up();
        $this->assertStringContainsString("'apertura'", $this->originConstraint());
        $this->assertEquals($history, DB::table('fondos_caja_autorizados')->orderBy('id')->get());

        $row = [
            'caja_id' => $this->register, 'fecha_operativa' => CommercialDate::today(), 'monto' => '60000',
            'usuario_autorizante_id' => $this->owner->id, 'motivo' => 'Prueba de restricción',
        ];
        foreach ([['cierre', null], ['apertura', $expired->cierre_origen_id], ['desconocido', null]] as [$origin, $closing]) {
            try {
                DB::transaction(fn () => DB::table('fondos_caja_autorizados')->insert($row + [
                    'origen' => $origin, 'cierre_origen_id' => $closing, 'clave_idempotencia' => (string) Str::uuid(),
                ]));
                $this->fail('PostgreSQL aceptó una relación de origen inválida.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
                $this->assertStringContainsString('fondos_caja_origen_check', $exception->getMessage());
            }
        }
        $this->actingAs($this->owner)->post(route('cash.funds.opening.store'), $this->authorization())->assertSessionHasNoErrors();
        try {
            $migration->down();
            $this->fail('La reversión aceptó una autorización de apertura existente.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fondos de apertura independientes', $exception->getMessage());
        }
        $this->assertStringContainsString("'apertura'", $this->originConstraint());
        $this->assertDatabaseHas('fondos_caja_autorizados', ['origen' => 'apertura', 'monto' => '60000.00']);
    }

    private function expireClosingFund(string $past = '2026-11-06 22:00:00 UTC'): FondoCajaAutorizado
    {
        $now = CarbonImmutable::now('UTC');
        $this->travelTo(CarbonImmutable::parse($past));
        $session = $this->dailyOpen($this->cashier, $this->register, '60000');
        $fund = $this->dailyClose($this->owner, $session, '60000', '60000')->cierre->fondoSiguiente;
        $this->travelTo($now);

        return $fund;
    }

    private function authorization(): array
    {
        return [
            'caja_id' => $this->register, 'fecha_operativa' => CommercialDate::today(), 'monto' => '60000',
            'motivo' => 'Efectivo contado para la jornada', 'confirmacion' => '1', 'clave_idempotencia' => (string) Str::uuid(),
        ];
    }

    private function opening(FondoCajaAutorizado $fund): array
    {
        return [
            'caja_id' => $this->register, 'fondo_autorizado_id' => $fund->id,
            'fecha_operativa' => $fund->fecha_operativa->toDateString(), 'clave_idempotencia' => (string) Str::uuid(),
        ];
    }

    private function originConstraint(): string
    {
        return DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint
            WHERE conrelid = 'fondos_caja_autorizados'::regclass AND conname = 'fondos_caja_origen_check'");
    }
}
