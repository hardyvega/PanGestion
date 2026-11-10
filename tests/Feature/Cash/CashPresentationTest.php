<?php

declare(strict_types=1);

namespace Tests\Feature\Cash;

use App\Enums\PermissionCode as P;
use App\Models\User;
use App\Services\Cash\CashService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\TestCase;

final class CashPresentationTest extends TestCase
{
    use DailyCashFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 15:23:47 UTC'));
    }

    #[DataProvider('openingTimes')]
    public function test_open_session_header_uses_persisted_opening_in_chile(string $instant, string $day, string $localTime, string $offset, bool $withHistory): void
    {
        $this->travelTo(CarbonImmutable::parse($instant));
        $cashier = $this->cashier();
        $register = $this->dailyRegister();
        $session = $this->dailyOpen($cashier, $register);
        $storedOpening = $session->getRawOriginal('abierta_en');
        $actor = $withHistory ? $this->dailyActor([P::CashSessionsView]) : $cashier;
        $this->travel(1)->hours();

        $response = $this->actingAs($actor)->get(route('cash.index'));

        $response->assertOk()->assertSeeInOrder([
            'Abierta por Pepita Vega', 'Jornada:', $day, 'Apertura real:', $localTime, '(America/Santiago)',
        ])->assertSee('datetime="'.$offset.'"', false)->assertSee('Abierta');
        $this->assertSame($storedOpening, $session->fresh()->getRawOriginal('abierta_en'));
        $this->assertNull($session->fresh()->cerrada_en);
        $this->assertSame(0, DB::table('movimientos_caja')->count());
        $this->assertSame(0, DB::table('cierres_caja')->count());
        if ($withHistory) {
            $response->assertSee('Historial de sesiones')->assertDontSee('Registrar movimiento manual');
        } else {
            $response->assertDontSee('Historial de sesiones')->assertSee('Registrar movimiento manual');
        }
    }

    public static function openingTimes(): array
    {
        $times = [
            'summer' => ['2026-10-19 15:23:47 UTC', '19/10/2026', '19/10/2026 12:23:47', '2026-10-19T12:23:47-03:00'],
            'winter' => ['2026-07-19 15:23:47 UTC', '19/07/2026', '19/07/2026 11:23:47', '2026-07-19T11:23:47-04:00'],
            'utc-next-day' => ['2026-10-20 01:23:47 UTC', '19/10/2026', '19/10/2026 22:23:47', '2026-10-19T22:23:47-03:00'],
        ];
        $cases = [];
        foreach ($times as $name => $values) {
            $cases[$name.'-operational'] = [...$values, false];
            $cases[$name.'-overview'] = [...$values, true];
        }

        return $cases;
    }

    public function test_previous_jornada_is_not_replaced_by_the_current_date(): void
    {
        $session = $this->dailyOpen($this->cashier(), $this->dailyRegister());
        $reader = $this->dailyActor([P::CashSessionsView]);
        $this->travel(1)->days();

        $this->actingAs($reader)->get(route('cash.index', ['session' => $session->id]))
            ->assertOk()->assertSee('datetime="2026-10-19"', false)
            ->assertSee('19/10/2026 12:23:47')->assertDontSee('20/10/2026 12:23:47');
        $this->assertSame('2026-10-19', $session->fresh()->fecha_operativa->format('Y-m-d'));
    }

    public function test_closed_header_preserves_snapshot_names_and_hides_mutation_forms(): void
    {
        $cashier = $this->cashier();
        $register = $this->dailyRegister();
        DB::table('cajas')->where('id', $register)->update(['nombre' => 'Caja original']);
        $session = $this->dailyOpen($cashier, $register, '10000');
        $owner = $this->dailyActor([P::CashSessionsView, P::CashSessionsClose, P::CashFundsAuthorize]);
        $closed = $this->dailyClose($owner, $session, '10000', '5000');
        $snapshot = $closed->cierre->resumen;
        DB::table('usuarios')->where('id', $cashier->id)->update(['nombre' => 'Nombre nuevo']);
        DB::table('cajas')->where('id', $register)->update(['nombre' => 'Caja nueva']);

        $this->actingAs($owner)->get(route('cash.index', ['session' => $session->id]))
            ->assertOk()->assertSee('Caja original')->assertSee('Abierta por Pepita Vega')
            ->assertSee('19/10/2026 12:23:47')->assertSee('Cerrada')
            ->assertDontSee('Registrar movimiento manual')->assertDontSee('Confirmar cierre definitivo');
        $this->assertSame($snapshot, $closed->cierre->fresh()->resumen);
        $this->assertSame('10000.00', (string) $session->fresh()->monto_teorico_cierre);
        $this->assertSame(1, DB::table('cierres_caja')->count());
        $this->assertSame(1, DB::table('movimientos_caja')->count());
    }

    public function test_manual_type_labels_and_accessible_initial_help_match_the_existing_codes(): void
    {
        $cashier = $this->cashier();
        $session = $this->dailyOpen($cashier, $this->dailyRegister());
        $response = $this->actingAs($cashier)->get(route('cash.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $options = $xpath->query('//select[@name="tipo_movimiento"]/option');

        $this->assertCount(3, $options);
        foreach (self::movementTypes() as $index => [$code, $label, $help]) {
            $option = $options->item($index);
            $this->assertSame($code, $option->getAttribute('value'));
            $this->assertSame($label, $option->textContent);
            $this->assertSame($help, $option->getAttribute('data-description'));
        }
        $select = $xpath->query('//select[@name="tipo_movimiento"]')->item(0);
        $this->assertSame('manual-movement-help-'.$session->id, $select->getAttribute('aria-describedby'));
        $help = $xpath->query('//*[@data-cash-movement-help]')->item(0);
        $this->assertSame(self::movementTypes()[0][2], $help->textContent);
        $this->assertSame('polite', $help->getAttribute('aria-live'));
        $this->assertSame(0, DB::table('movimientos_caja')->count());
    }

    #[DataProvider('movementTypes')]
    public function test_validation_preserves_selected_type_and_matching_help(string $code, string $label, string $help): void
    {
        $cashier = $this->cashier();
        $session = $this->dailyOpen($cashier, $this->dailyRegister());
        $this->actingAs($cashier)->from(route('cash.index'))->post(route('cash.movements.store', $session), [
            'tipo_movimiento' => $code, 'monto' => '', 'concepto' => 'Movimiento incompleto',
        ])->assertSessionHasErrors('monto');

        $response = $this->get(route('cash.index'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $selected = $xpath->query('//select[@name="tipo_movimiento"]/option[@selected]');
        $this->assertCount(1, $selected);
        $this->assertSame($code, $selected->item(0)->getAttribute('value'));
        $this->assertSame($label, $selected->item(0)->textContent);
        $this->assertSame($help, $xpath->query('//*[@data-cash-movement-help]')->item(0)->textContent);
        $this->assertSame(0, DB::table('movimientos_caja')->count());
    }

    #[DataProvider('movementTypes')]
    public function test_posted_technical_types_keep_the_same_balance_and_permissions(string $code, string $label, string $help): void
    {
        $cashier = $this->cashier();
        $reader = $this->dailyActor([P::CashSessionsView]);
        $session = $this->dailyOpen($cashier, $this->dailyRegister(), '10000');
        $assignments = DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson();

        $this->actingAs($cashier)->post(route('cash.movements.store', $session), [
            'tipo_movimiento' => $code, 'monto' => '1000', 'concepto' => 'Movimiento manual autorizado',
        ])->assertSessionHasNoErrors()->assertRedirect(route('cash.index', ['session' => $session->id]));
        $this->get(route('cash.index'))->assertOk()->assertSee($label)->assertSee($help);

        $this->assertDatabaseHas('movimientos_caja', [
            'sesion_caja_id' => $session->id, 'usuario_id' => $cashier->id, 'tipo_movimiento' => $code,
            'monto' => '1000.00', 'pago_venta_id' => null, 'pago_pedido_id' => null, 'cierre_caja_id' => null,
        ]);
        $expected = $code === 'ingreso' ? '11000.00' : '9000.00';
        $this->assertSame($expected, app(CashService::class)->overview($reader, (int) $session->id)['theoretical']);
        $this->assertSame('10000.00', (string) $session->fresh()->monto_inicial);
        $this->assertSame($assignments, DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson());
        $this->assertNull($session->fresh()->cerrada_en);
        $this->assertSame(1, DB::table('movimientos_caja')->count());
        $this->assertSame(0, DB::table('cierres_caja')->count());
    }

    public function test_view_permission_does_not_allow_manual_movements_or_closing(): void
    {
        $reader = $this->dailyActor([P::CashSessionsView]);
        $session = $this->dailyOpen($this->cashier(), $this->dailyRegister());

        $this->actingAs($reader)->get(route('cash.index'))->assertOk()
            ->assertSee('Apertura real:')->assertDontSee('Registrar movimiento manual')
            ->assertDontSee('Confirmar cierre definitivo');
        $this->post(route('cash.movements.store', $session), [
            'tipo_movimiento' => 'ingreso', 'monto' => '1000', 'concepto' => 'No autorizado',
        ])->assertForbidden();
        $this->post(route('cash.sessions.close', $session), [])->assertForbidden();
        $this->assertNull($session->fresh()->cerrada_en);
        $this->assertSame(0, DB::table('movimientos_caja')->count());
        $this->assertSame(0, DB::table('cierres_caja')->count());
    }

    public static function movementTypes(): array
    {
        return [
            ['ingreso', 'Ingreso — Entrada adicional de efectivo', 'Dinero adicional que entra a caja. No utilizar para registrar ventas.'],
            ['egreso', 'Egreso — Pago de gasto operativo', 'Dinero que sale para pagar un gasto operativo de la panadería.'],
            ['retiro', 'Retiro — Extracción de efectivo', 'Efectivo extraído de caja que no corresponde a un gasto operativo.'],
        ];
    }

    private function cashier(): User
    {
        $user = $this->dailyActor([P::CashSessionsOpen, P::CashManualMovementsManage]);
        $user->forceFill(['nombre' => 'Pepita', 'apellido' => 'Vega'])->save();

        return $user;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
