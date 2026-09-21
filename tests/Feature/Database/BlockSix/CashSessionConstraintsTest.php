<?php

namespace Tests\Feature\Database\BlockSix;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class CashSessionConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $roleId;

    private int $cashRegisterId;

    private int $openingUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->cashRegisterId = $this->createCashRegister();
        $this->openingUserId = $this->createUser($this->roleId);
    }

    public function test_open_cash_session_can_be_created_with_minimum_data_and_defaults(): void
    {
        $sessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $session = DB::table('sesiones_caja')->where('id', $sessionId)->first();

        $this->assertNotNull($session);
        $this->assertSame($sessionId, $session->id);
        $this->assertSame($this->cashRegisterId, $session->caja_id);
        $this->assertSame($this->openingUserId, $session->usuario_apertura_id);
        $this->assertSame('0.00', $session->monto_inicial);
        $this->assertNotNull($session->abierta_en);
        $this->assertNull($session->usuario_cierre_id);
        $this->assertNull($session->cerrada_en);
        $this->assertNull($session->monto_cierre_declarado);
        $this->assertNull($session->monto_teorico_cierre);
        $this->assertNull($session->observacion_cierre);
        $this->assertNotNull($session->created_at);
        $this->assertNotNull($session->updated_at);
    }

    #[DataProvider('validOpeningAmounts')]
    public function test_valid_opening_amount_is_accepted(string $amount): void
    {
        $sessionId = $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            ['monto_inicial' => $amount],
        );

        $this->assertSame($amount, DB::table('sesiones_caja')->where('id', $sessionId)->value('monto_inicial'));
    }

    public static function validOpeningAmounts(): array
    {
        return [
            'zero' => ['0.00'],
            'one' => ['1.00'],
            'positive decimal' => ['125.50'],
        ];
    }

    #[DataProvider('invalidOpeningAmounts')]
    public function test_invalid_opening_amount_is_rejected(string $amount): void
    {
        $this->expectException(QueryException::class);
        $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            ['monto_inicial' => $amount],
        );
    }

    public static function invalidOpeningAmounts(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('individualClosingFields')]
    public function test_open_session_rejects_individual_closing_field(string $field, mixed $value): void
    {
        if ($field === 'usuario_cierre_id') {
            $value = $this->createClosingUser();
        }

        $this->expectException(QueryException::class);
        $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            [$field => $value],
        );
    }

    public static function individualClosingFields(): array
    {
        return [
            'closing user' => ['usuario_cierre_id', null],
            'closing timestamp' => ['cerrada_en', '2026-09-21 16:00:00-03'],
            'declared closing amount' => ['monto_cierre_declarado', '100.00'],
            'theoretical closing amount' => ['monto_teorico_cierre', '100.00'],
            'closing observation' => ['observacion_cierre', 'Cierre normal'],
        ];
    }

    #[DataProvider('validClosingObservations')]
    public function test_closed_cash_session_accepts_optional_valid_observation(?string $observation): void
    {
        $sessionId = $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($this->createClosingUser(), [
                'observacion_cierre' => $observation,
            ]),
        );

        $this->assertSame(
            $observation,
            DB::table('sesiones_caja')->where('id', $sessionId)->value('observacion_cierre'),
        );
    }

    public static function validClosingObservations(): array
    {
        return [
            'null' => [null],
            'normal closing' => ['Cierre normal'],
            'closing difference' => ['Diferencia $2.000'],
        ];
    }

    #[DataProvider('requiredClosingFields')]
    public function test_closed_cash_session_requires_each_required_field(string $field): void
    {
        $overrides = $this->closedSessionOverrides($this->createClosingUser());
        $overrides[$field] = null;

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, $overrides);
    }

    public static function requiredClosingFields(): array
    {
        return [
            'closing user' => ['usuario_cierre_id'],
            'declared closing amount' => ['monto_cierre_declarado'],
            'theoretical closing amount' => ['monto_teorico_cierre'],
        ];
    }

    #[DataProvider('validDeclaredClosingAmounts')]
    public function test_valid_declared_closing_amount_is_accepted(string $amount): void
    {
        $sessionId = $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($this->createClosingUser(), [
                'monto_cierre_declarado' => $amount,
            ]),
        );

        $this->assertSame(
            $amount,
            DB::table('sesiones_caja')->where('id', $sessionId)->value('monto_cierre_declarado'),
        );
    }

    public static function validDeclaredClosingAmounts(): array
    {
        return [
            'zero' => ['0.00'],
            'positive' => ['150.00'],
        ];
    }

    #[DataProvider('invalidDeclaredClosingAmounts')]
    public function test_invalid_declared_closing_amount_is_rejected(string $amount): void
    {
        $overrides = $this->closedSessionOverrides($this->createClosingUser(), [
            'monto_cierre_declarado' => $amount,
        ]);

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, $overrides);
    }

    public static function invalidDeclaredClosingAmounts(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validTheoreticalClosingAmounts')]
    public function test_valid_theoretical_closing_amount_is_accepted(string $amount): void
    {
        $sessionId = $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($this->createClosingUser(), [
                'monto_teorico_cierre' => $amount,
            ]),
        );

        $this->assertSame(
            $amount,
            DB::table('sesiones_caja')->where('id', $sessionId)->value('monto_teorico_cierre'),
        );
    }

    public static function validTheoreticalClosingAmounts(): array
    {
        return [
            'negative' => ['-25.50'],
            'zero' => ['0.00'],
            'positive' => ['200.00'],
        ];
    }

    public function test_nan_theoretical_closing_amount_is_rejected(): void
    {
        $overrides = $this->closedSessionOverrides($this->createClosingUser(), [
            'monto_teorico_cierre' => 'NaN',
        ]);

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, $overrides);
    }

    #[DataProvider('validClosingTimes')]
    public function test_valid_closing_time_is_accepted(string $closingTime): void
    {
        $sessionId = $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($this->createClosingUser(), [
                'cerrada_en' => $closingTime,
            ]),
        );

        $this->assertSame(1, DB::table('sesiones_caja')->where('id', $sessionId)->count());
    }

    public static function validClosingTimes(): array
    {
        return [
            'equal to opening' => ['2026-09-21 08:00:00-03'],
            'after opening' => ['2026-09-21 16:00:00-03'],
        ];
    }

    public function test_closing_before_opening_is_rejected(): void
    {
        $overrides = $this->closedSessionOverrides($this->createClosingUser(), [
            'cerrada_en' => '2026-09-21 07:59:59-03',
        ]);

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, $overrides);
    }

    #[DataProvider('invalidClosingObservations')]
    public function test_invalid_closing_observation_is_rejected(string $observation): void
    {
        $overrides = $this->closedSessionOverrides($this->createClosingUser(), [
            'observacion_cierre' => $observation,
        ]);

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, $overrides);
    }

    public static function invalidClosingObservations(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Cierre'],
            'trailing space' => ['Cierre '],
        ];
    }

    public function test_cash_register_can_have_no_open_sessions(): void
    {
        $this->assertSame(
            0,
            DB::table('sesiones_caja')
                ->where('caja_id', $this->cashRegisterId)
                ->whereNull('cerrada_en')
                ->count(),
        );
    }

    public function test_cash_register_can_have_one_open_session(): void
    {
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->assertSame(
            1,
            DB::table('sesiones_caja')
                ->where('caja_id', $this->cashRegisterId)
                ->whereNull('cerrada_en')
                ->count(),
        );
    }

    public function test_cash_register_can_have_multiple_closed_sessions(): void
    {
        $closingUserId = $this->createClosingUser();
        $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($closingUserId),
        );
        $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($closingUserId, [
                'abierta_en' => '2026-09-22 08:00:00-03',
                'cerrada_en' => '2026-09-22 16:00:00-03',
            ]),
        );

        $this->assertSame(
            2,
            DB::table('sesiones_caja')
                ->where('caja_id', $this->cashRegisterId)
                ->whereNotNull('cerrada_en')
                ->count(),
        );
    }

    public function test_cash_register_can_have_closed_history_and_one_open_session(): void
    {
        $this->createCashSession(
            $this->cashRegisterId,
            $this->openingUserId,
            $this->closedSessionOverrides($this->createClosingUser()),
        );
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->assertSame(2, DB::table('sesiones_caja')->where('caja_id', $this->cashRegisterId)->count());
        $this->assertSame(
            1,
            DB::table('sesiones_caja')
                ->where('caja_id', $this->cashRegisterId)
                ->whereNull('cerrada_en')
                ->count(),
        );
    }

    public function test_second_open_session_for_same_cash_register_is_rejected(): void
    {
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);
    }

    public function test_different_cash_registers_can_each_have_open_session(): void
    {
        $secondCashRegisterId = $this->createCashRegister([
            'codigo' => 'caja_secundaria',
            'nombre' => 'Caja secundaria',
        ]);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $this->createCashSession($secondCashRegisterId, $this->openingUserId);

        $this->assertSame(2, DB::table('sesiones_caja')->whereNull('cerrada_en')->count());
    }

    public function test_new_session_can_open_after_previous_session_is_closed(): void
    {
        $firstSessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId, [
            'abierta_en' => '2026-09-21 08:00:00-03',
        ]);
        $closingUserId = $this->createClosingUser();

        $this->assertSame(
            1,
            DB::table('sesiones_caja')->where('id', $firstSessionId)->update([
                'usuario_cierre_id' => $closingUserId,
                'cerrada_en' => '2026-09-21 16:00:00-03',
                'monto_cierre_declarado' => '100.00',
                'monto_teorico_cierre' => '100.00',
                'observacion_cierre' => null,
            ]),
        );

        $this->createCashSession($this->cashRegisterId, $this->openingUserId, [
            'abierta_en' => '2026-09-22 08:00:00-03',
        ]);

        $this->assertSame(2, DB::table('sesiones_caja')->where('caja_id', $this->cashRegisterId)->count());
        $this->assertSame(
            1,
            DB::table('sesiones_caja')
                ->where('caja_id', $this->cashRegisterId)
                ->whereNull('cerrada_en')
                ->count(),
        );
    }

    public function test_cash_session_rejects_missing_cash_register(): void
    {
        $missingCashRegisterId = $this->cashRegisterId;
        $this->assertSame(1, DB::table('cajas')->where('id', $missingCashRegisterId)->delete());

        $this->expectException(QueryException::class);
        $this->createCashSession($missingCashRegisterId, $this->openingUserId);
    }

    public function test_cash_session_rejects_missing_opening_user(): void
    {
        $missingOpeningUserId = $this->openingUserId;
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingOpeningUserId)->delete());

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $missingOpeningUserId);
    }

    public function test_closed_cash_session_rejects_missing_closing_user(): void
    {
        $missingClosingUserId = $this->createClosingUser();
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingClosingUserId)->delete());
        $overrides = $this->closedSessionOverrides($missingClosingUserId);

        $this->expectException(QueryException::class);
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, $overrides);
    }

    private function createClosingUser(): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Cierre',
            'nombre_usuario' => 'cierre',
        ]);
    }

    private function closedSessionOverrides(int $closingUserId, array $overrides = []): array
    {
        return array_merge([
            'usuario_cierre_id' => $closingUserId,
            'abierta_en' => '2026-09-21 08:00:00-03',
            'cerrada_en' => '2026-09-21 16:00:00-03',
            'monto_cierre_declarado' => '100.00',
            'monto_teorico_cierre' => '100.00',
            'observacion_cierre' => null,
        ], $overrides);
    }
}
