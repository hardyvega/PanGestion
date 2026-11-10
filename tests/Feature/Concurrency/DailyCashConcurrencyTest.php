<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\PermissionCode as P;
use App\Support\CommercialDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Cash\DailyCashFixtures;
use Tests\Support\Concurrency\ProcessRace;
use Tests\TestCase;

final class DailyCashConcurrencyTest extends TestCase
{
    use DailyCashFixtures;

    protected function tearDown(): void
    {
        try {
            $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'");
            if ($tables !== []) {
                DB::statement('TRUNCATE TABLE '.implode(', ', array_map(static fn (object $table): string => '"'.str_replace('"', '""', $table->tablename).'"', $tables)).' RESTART IDENTITY CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_cashiers_cannot_consume_the_same_fund_concurrently(): void
    {
        $first = $this->dailyActor([P::CashSessionsOpen]);
        $second = $this->dailyActor([P::CashSessionsOpen]);
        $register = $this->dailyRegister();
        $fund = $this->dailyFund($register, '50000');
        $payload = ['action' => 'open', 'clock' => now('UTC')->toISOString(), 'register_id' => $register, 'fund_id' => $fund->id, 'date' => CommercialDate::today()];
        $results = ProcessRace::run('cash_operate', [
            [...$payload, 'actor_id' => $first->id, 'uuid' => (string) Str::uuid()],
            [...$payload, 'actor_id' => $second->id, 'uuid' => (string) Str::uuid()],
        ]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
        $this->assertSame(1, DB::table('sesiones_caja')->count());
        $this->assertNotNull($fund->fresh()->utilizada_en);
        $this->assertSame(0, DB::table('movimientos_caja')->count());
    }

    public function test_simultaneous_close_retries_have_one_snapshot_withdrawal_and_next_fund(): void
    {
        [$owner, $session, $payload] = $this->closingRace();
        $results = ProcessRace::run('cash_operate', [$payload, $payload]);
        $this->assertCount(2, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
        $this->assertSame(1, DB::table('cierres_caja')->count());
        $this->assertSame(1, DB::table('movimientos_caja')->whereNotNull('cierre_caja_id')->count());
        $this->assertSame(2, DB::table('fondos_caja_autorizados')->count());
        $this->assertSame('0.00', (string) $session->fresh()->cierre->diferencia);
    }

    public function test_two_owners_cannot_authorize_different_opening_funds_for_the_same_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06 22:00:00 UTC'));
        $first = $this->dailyActor([P::CashFundsAuthorize, P::CashSessionsClose]);
        $second = $this->dailyActor([P::CashFundsAuthorize]);
        $cashier = $this->dailyActor([P::CashSessionsOpen]);
        $register = $this->dailyRegister();
        DB::table('cajas')->where('id', $register)->update(['codigo' => 'principal', 'nombre' => 'Caja principal']);
        $session = $this->dailyOpen($cashier, $register, '60000');
        $this->dailyClose($first, $session, '60000', '60000');
        $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00:00 UTC'));
        $payload = [
            'action' => 'authorize_opening', 'clock' => now('UTC')->toISOString(), 'register_id' => $register,
            'date' => CommercialDate::today(), 'reason' => 'Conteo de apertura concurrente',
        ];
        $results = ProcessRace::run('cash_operate', [
            [...$payload, 'actor_id' => $first->id, 'amount' => '60000', 'uuid' => (string) Str::uuid()],
            [...$payload, 'actor_id' => $second->id, 'amount' => '65000', 'uuid' => (string) Str::uuid()],
        ]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
        $fund = DB::table('fondos_caja_autorizados')->where('origen', 'apertura')->sole();
        $this->assertSame($results[0]['ok'] ? '60000.00' : '65000.00', $fund->monto);
        $this->assertNull($fund->utilizada_en);
        $this->assertSame(3, DB::table('fondos_caja_autorizados')->count());
        $this->assertSame(1, DB::table('sesiones_caja')->count());
        $this->assertSame(0, DB::table('movimientos_caja')->count());
    }

    public function test_two_late_confirmations_cannot_close_the_same_session_twice(): void
    {
        [, $session, $payload] = $this->closingRace();
        $recovery = CarbonImmutable::parse($payload['clock'])->addDays(2);
        $payload['clock'] = $recovery->toISOString();
        $payload['next_date'] = CommercialDate::fromInstant($recovery);

        $results = ProcessRace::run('cash_operate', [$payload, [...$payload, 'uuid' => (string) Str::uuid()]]);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
        $closing = $session->fresh()->cierre;
        $this->assertSame($session->fecha_operativa->format('Y-m-d'), $closing->fecha_operativa->format('Y-m-d'));
        $this->assertSame($payload['next_date'], $closing->fondoSiguiente->fecha_operativa->format('Y-m-d'));
        $this->assertSame(1, DB::table('cierres_caja')->count());
        $this->assertSame(1, DB::table('movimientos_caja')->where('cierre_caja_id', $closing->id)->count());
        $this->assertSame(2, DB::table('fondos_caja_autorizados')->count());
    }

    #[DataProvider('incomeWriters')]
    public function test_close_racing_with_income_never_confirms_an_unreviewed_balance(string $action): void
    {
        [$owner, $session, $payload] = $this->closingRace();
        $cashier = $this->dailyActor([P::CashManualMovementsManage]);
        $results = ProcessRace::run('cash_operate', [$payload, [
            'action' => $action, 'clock' => $payload['clock'], 'actor_id' => $cashier->id, 'session_id' => $session->id,
        ]]);
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['ok']), json_encode($results));
        if ($session->fresh()->cerrada_en !== null) {
            $this->assertSame('140000.00', (string) $session->fresh()->cierre->esperado_antes_retiro);
            $this->assertSame(1, DB::table('movimientos_caja')->count());
        } else {
            $this->assertSame(0, DB::table('cierres_caja')->count());
            $this->assertSame('1.00', DB::table('movimientos_caja')->sole()->monto);
            $this->assertSame(1, DB::table('fondos_caja_autorizados')->count());
        }
    }

    public static function incomeWriters(): array
    {
        return [['movement'], ['raw_movement']];
    }

    private function closingRace(): array
    {
        $owner = $this->dailyActor([P::CashFundsAuthorize, P::CashSessionsClose]);
        $cashier = $this->dailyActor([P::CashSessionsOpen]);
        $session = $this->dailyOpen($cashier, $this->dailyRegister());

        return [$owner, $session, [
            'action' => 'close', 'clock' => now('UTC')->toISOString(), 'actor_id' => $owner->id, 'session_id' => $session->id,
            'counted' => '140000', 'expected' => '140000', 'next_fund' => '50000',
            'next_date' => CarbonImmutable::parse(CommercialDate::today())->addDay()->format('Y-m-d'),
            'uuid' => (string) Str::uuid(),
        ]];
    }
}
