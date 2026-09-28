<?php

namespace Tests\Feature\Database\BlockEight;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class SaleConstraintsTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const UUID_THREE = '33333333-3333-4333-8333-333333333333';

    private int $cashSessionId;

    private int $clientId;

    private int $roleId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->userId = $this->createUser($this->roleId);
        $this->clientId = $this->createClient();
        $cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($cashRegisterId, $this->userId);
    }

    public function test_sale_sequence_uses_zero_and_timestamp_defaults(): void
    {
        $date = $this->createSaleSequence('2026-09-24');
        $sequence = DB::table('correlativos_venta_diarios')->where('fecha_comercial', $date)->first();

        $this->assertNotNull($sequence);
        $this->assertSame(0, $sequence->ultimo_numero);
        $this->assertNotNull($sequence->created_at);
        $this->assertNotNull($sequence->updated_at);
    }

    public function test_sale_sequence_accepts_positive_last_number(): void
    {
        $date = $this->createSaleSequence('2026-09-24', ['ultimo_numero' => 27]);

        $this->assertSame(
            27,
            DB::table('correlativos_venta_diarios')->where('fecha_comercial', $date)->value('ultimo_numero'),
        );
    }

    public function test_sale_sequence_rejects_negative_last_number(): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleSequence('2026-09-24', ['ultimo_numero' => -1]);
    }

    public function test_sale_sequence_rejects_duplicate_commercial_date(): void
    {
        $this->createSaleSequence('2026-09-24');

        $this->expectException(QueryException::class);
        $this->createSaleSequence('2026-09-24');
    }

    public function test_sale_sequence_accepts_distinct_commercial_dates(): void
    {
        $this->createSaleSequence('2026-09-24');
        $this->createSaleSequence('2026-09-25');

        $this->assertSame(2, DB::table('correlativos_venta_diarios')->count());
    }

    public function test_direct_sale_can_be_created_with_database_defaults(): void
    {
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
        );
        $sale = DB::table('ventas')->where('id', $saleId)->first();

        $this->assertNotNull($sale);
        $this->assertSame('2026-09-24', $sale->fecha_comercial);
        $this->assertSame(1, $sale->numero_diario);
        $this->assertSame(self::UUID_ONE, $sale->clave_idempotencia);
        $this->assertSame($this->userId, $sale->usuario_id);
        $this->assertSame($this->cashSessionId, $sale->sesion_caja_id);
        $this->assertNull($sale->cliente_id);
        $this->assertNull($sale->pedido_id);
        $this->assertSame('completada', $sale->estado);
        $this->assertSame('1000.00', $sale->total);
        $this->assertSame('0.00', $sale->monto_anticipo_aplicado);
        $this->assertNotNull($sale->completada_en);
        $this->assertNull($sale->anulada_en);
        $this->assertNull($sale->usuario_anulador_id);
        $this->assertNull($sale->motivo_anulacion);
        $this->assertNull($sale->observacion);
        $this->assertNotNull($sale->created_at);
        $this->assertNotNull($sale->updated_at);
    }

    public function test_sale_rejects_omitted_completion_timestamp_as_not_null_violation(): void
    {
        $data = $this->saleData($this->userId, $this->cashSessionId, self::UUID_ONE);
        unset($data['completada_en']);

        try {
            DB::table('ventas')->insert($data);
            $this->fail('Expected the missing completion timestamp to be rejected.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', (string) $exception->getCode());
        }
    }

    public function test_direct_sale_accepts_optional_client(): void
    {
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['cliente_id' => $this->clientId],
        );

        $this->assertSame($this->clientId, DB::table('ventas')->where('id', $saleId)->value('cliente_id'));
        $this->assertNull(DB::table('ventas')->where('id', $saleId)->value('pedido_id'));
    }

    public function test_sale_from_order_with_client_is_accepted(): void
    {
        $orderId = $this->createOrder($this->clientId, $this->userId);
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['cliente_id' => $this->clientId, 'pedido_id' => $orderId],
        );

        $sale = DB::table('ventas')->where('id', $saleId)->first();
        $this->assertSame($this->clientId, $sale->cliente_id);
        $this->assertSame($orderId, $sale->pedido_id);
    }

    public function test_sale_from_order_without_client_is_rejected(): void
    {
        $orderId = $this->createOrder($this->clientId, $this->userId);

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['pedido_id' => $orderId],
        );
    }

    public function test_multiple_sales_can_share_client_user_session_and_null_order(): void
    {
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['cliente_id' => $this->clientId],
        );
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_TWO,
            ['numero_diario' => 2, 'cliente_id' => $this->clientId],
        );

        $this->assertSame(2, DB::table('ventas')->where('cliente_id', $this->clientId)->count());
        $this->assertSame(2, DB::table('ventas')->where('usuario_id', $this->userId)->count());
        $this->assertSame(2, DB::table('ventas')->where('sesion_caja_id', $this->cashSessionId)->count());
        $this->assertSame(2, DB::table('ventas')->whereNull('pedido_id')->count());
    }

    public function test_daily_number_can_vary_by_sale_or_commercial_date(): void
    {
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_TWO,
            ['numero_diario' => 2],
        );
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_THREE,
            [
                'fecha_comercial' => '2026-09-25',
                'numero_diario' => 1,
                'completada_en' => '2026-09-25 12:00:00-03',
            ],
        );

        $this->assertSame(3, DB::table('ventas')->count());
    }

    public function test_duplicate_commercial_date_and_daily_number_is_rejected(): void
    {
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE);

        $this->expectException(QueryException::class);
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_TWO);
    }

    #[DataProvider('invalidDailyNumbers')]
    public function test_invalid_daily_number_is_rejected(int $number): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['numero_diario' => $number],
        );
    }

    public static function invalidDailyNumbers(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_commercial_date_uses_america_santiago_when_utc_date_differs(): void
    {
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            [
                'fecha_comercial' => '2026-09-24',
                'completada_en' => '2026-09-25 01:30:00+00',
            ],
        );

        $this->assertSame('2026-09-24', DB::table('ventas')->where('id', $saleId)->value('fecha_comercial'));
    }

    public function test_utc_date_is_rejected_when_it_differs_from_chile_commercial_date(): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            [
                'fecha_comercial' => '2026-09-25',
                'completada_en' => '2026-09-25 01:30:00+00',
            ],
        );
    }

    public function test_distinct_sale_idempotency_keys_are_accepted(): void
    {
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_TWO,
            ['numero_diario' => 2],
        );

        $this->assertSame(2, DB::table('ventas')->distinct()->count('clave_idempotencia'));
    }

    public function test_duplicate_sale_idempotency_key_is_rejected(): void
    {
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE);

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['numero_diario' => 2],
        );
    }

    #[DataProvider('validAdvanceCombinations')]
    public function test_valid_advance_combination_is_accepted(
        string $total,
        string $advance,
        bool $withOrder,
    ): void {
        $overrides = [
            'total' => $total,
            'monto_anticipo_aplicado' => $advance,
        ];

        if ($withOrder) {
            $overrides['cliente_id'] = $this->clientId;
            $overrides['pedido_id'] = $this->createOrder($this->clientId, $this->userId);
        }

        $saleId = $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE, $overrides);

        $this->assertSame($total, DB::table('ventas')->where('id', $saleId)->value('total'));
        $this->assertSame($advance, DB::table('ventas')->where('id', $saleId)->value('monto_anticipo_aplicado'));
    }

    public static function validAdvanceCombinations(): array
    {
        return [
            'zero without order' => ['1000.00', '0.00', false],
            'zero with order' => ['1000.00', '0.00', true],
            'partial with order' => ['1000.00', '400.00', true],
            'equal to total with order' => ['1000.00', '1000.00', true],
            'zero total and zero advance' => ['0.00', '0.00', false],
        ];
    }

    #[DataProvider('invalidAdvanceCombinations')]
    public function test_invalid_advance_combination_is_rejected(
        string $total,
        string $advance,
        bool $withOrder,
    ): void {
        $overrides = [
            'total' => $total,
            'monto_anticipo_aplicado' => $advance,
        ];

        if ($withOrder) {
            $overrides['cliente_id'] = $this->clientId;
            $overrides['pedido_id'] = $this->createOrder($this->clientId, $this->userId);
        }

        $this->expectException(QueryException::class);
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE, $overrides);
    }

    public static function invalidAdvanceCombinations(): array
    {
        return [
            'negative' => ['1000.00', '-0.01', true],
            'NaN' => ['1000.00', 'NaN', true],
            'greater than total' => ['1000.00', '1000.01', true],
            'positive without order' => ['1000.00', '1.00', false],
        ];
    }

    #[DataProvider('invalidTotals')]
    public function test_invalid_sale_total_is_rejected(string $total): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['total' => $total],
        );
    }

    public static function invalidTotals(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_annulled_sale_with_complete_audit_is_accepted(): void
    {
        $annullingUserId = $this->createDistinctUser('anulador');
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            [
                'estado' => 'anulada',
                'anulada_en' => '2026-09-24 12:30:00-03',
                'usuario_anulador_id' => $annullingUserId,
                'motivo_anulacion' => 'Error de digitacion',
            ],
        );
        $sale = DB::table('ventas')->where('id', $saleId)->first();

        $this->assertSame('anulada', $sale->estado);
        $this->assertSame($annullingUserId, $sale->usuario_anulador_id);
        $this->assertSame('Error de digitacion', $sale->motivo_anulacion);
    }

    #[DataProvider('invalidSaleStates')]
    public function test_invalid_sale_state_is_rejected(string $state): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['estado' => $state],
        );
    }

    public static function invalidSaleStates(): array
    {
        return [
            'pending' => ['pendiente'],
            'uppercase completed' => ['COMPLETADA'],
            'uppercase annulled' => ['ANULADA'],
            'empty' => [''],
            'other' => ['procesada'],
        ];
    }

    #[DataProvider('invalidAnnulmentStructures')]
    public function test_inconsistent_annulment_structure_is_rejected(
        string $state,
        ?string $annulledAt,
        bool $withAnnullingUser,
        ?string $reason,
    ): void {
        $overrides = [
            'estado' => $state,
            'anulada_en' => $annulledAt,
            'usuario_anulador_id' => $withAnnullingUser ? $this->createDistinctUser('anulador') : null,
            'motivo_anulacion' => $reason,
        ];

        $this->expectException(QueryException::class);
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE, $overrides);
    }

    public static function invalidAnnulmentStructures(): array
    {
        return [
            'completed with timestamp' => ['completada', '2026-09-24 12:30:00-03', false, null],
            'completed with user' => ['completada', null, true, null],
            'completed with reason' => ['completada', null, false, 'Motivo'],
            'completed with full annulment' => ['completada', '2026-09-24 12:30:00-03', true, 'Motivo'],
            'annulled without timestamp' => ['anulada', null, true, 'Motivo'],
            'annulled without user' => ['anulada', '2026-09-24 12:30:00-03', false, 'Motivo'],
            'annulled without reason' => ['anulada', '2026-09-24 12:30:00-03', true, null],
        ];
    }

    public function test_annulment_at_or_after_completion_is_accepted(): void
    {
        $annullingUserId = $this->createDistinctUser('anulador');

        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            [
                'estado' => 'anulada',
                'anulada_en' => '2026-09-24 12:00:00-03',
                'usuario_anulador_id' => $annullingUserId,
                'motivo_anulacion' => 'Misma hora',
            ],
        );
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_TWO,
            [
                'numero_diario' => 2,
                'estado' => 'anulada',
                'anulada_en' => '2026-09-24 13:00:00-03',
                'usuario_anulador_id' => $annullingUserId,
                'motivo_anulacion' => 'Hora posterior',
            ],
        );

        $this->assertSame(2, DB::table('ventas')->where('estado', 'anulada')->count());
    }

    public function test_annulment_before_completion_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            [
                'estado' => 'anulada',
                'anulada_en' => '2026-09-24 11:59:59-03',
                'usuario_anulador_id' => $this->createDistinctUser('anulador'),
                'motivo_anulacion' => 'Hora invalida',
            ],
        );
    }

    #[DataProvider('invalidAnnulmentReasons')]
    public function test_invalid_annulment_reason_is_rejected(string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            [
                'estado' => 'anulada',
                'anulada_en' => '2026-09-24 12:30:00-03',
                'usuario_anulador_id' => $this->createDistinctUser('anulador'),
                'motivo_anulacion' => $reason,
            ],
        );
    }

    public static function invalidAnnulmentReasons(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Motivo'],
            'trailing space' => ['Motivo '],
        ];
    }

    public function test_valid_sale_observation_is_preserved(): void
    {
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['observacion' => 'Entrega en meson'],
        );

        $this->assertSame('Entrega en meson', DB::table('ventas')->where('id', $saleId)->value('observacion'));
    }

    #[DataProvider('invalidSaleObservations')]
    public function test_invalid_sale_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['observacion' => $observation],
        );
    }

    public static function invalidSaleObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Observacion'],
            'trailing space' => ['Observacion '],
        ];
    }

    public function test_second_sale_for_same_order_is_rejected(): void
    {
        $orderId = $this->createOrder($this->clientId, $this->userId);
        $overrides = ['cliente_id' => $this->clientId, 'pedido_id' => $orderId];
        $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE, $overrides);

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_TWO,
            array_merge($overrides, ['numero_diario' => 2]),
        );
    }

    public function test_completed_sale_can_exist_without_details_or_payments(): void
    {
        $saleId = $this->createSale($this->userId, $this->cashSessionId, self::UUID_ONE);

        $this->assertTrue(DB::table('ventas')->where('id', $saleId)->exists());
        $this->assertSame(0, DB::table('detalle_ventas')->where('venta_id', $saleId)->count());
        $this->assertSame(0, DB::table('pagos_venta')->where('venta_id', $saleId)->count());
    }

    public function test_sale_can_reference_pending_order_with_different_client(): void
    {
        $orderId = $this->createOrder($this->clientId, $this->userId);
        $otherClientId = $this->createClient(['nombre' => 'Cliente distinto']);
        $saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::UUID_ONE,
            ['cliente_id' => $otherClientId, 'pedido_id' => $orderId],
        );

        $this->assertSame('pendiente', DB::table('pedidos')->where('id', $orderId)->value('estado'));
        $this->assertSame($otherClientId, DB::table('ventas')->where('id', $saleId)->value('cliente_id'));
        $this->assertNotSame($this->clientId, $otherClientId);
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Distinto',
            'nombre_usuario' => $username,
        ]);
    }
}
