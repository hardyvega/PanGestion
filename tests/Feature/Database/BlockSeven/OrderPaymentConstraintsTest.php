<?php

namespace Tests\Feature\Database\BlockSeven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class OrderPaymentConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const UUID_THREE = '33333333-3333-4333-8333-333333333333';

    private int $cashRegisterId;

    private int $cashSessionId;

    private int $clientId;

    private int $creditMethodId;

    private int $debitMethodId;

    private int $effectiveMethodId;

    private int $orderId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->createRole());
        $this->clientId = $this->createClient();
        $this->orderId = $this->createOrder($this->clientId, $this->userId);
        $this->effectiveMethodId = $this->createPaymentMethod([
            'codigo' => 'efectivo',
            'nombre' => 'Efectivo',
        ]);
        $this->debitMethodId = $this->createPaymentMethod([
            'codigo' => 'debito',
            'nombre' => 'Débito',
        ]);
        $this->creditMethodId = $this->createPaymentMethod([
            'codigo' => 'credito',
            'nombre' => 'Crédito',
        ]);
        $this->cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($this->cashRegisterId, $this->userId);
    }

    public function test_order_payment_can_be_created_with_minimum_data_and_defaults(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
        );
        $payment = DB::table('pagos_pedido')->where('id', $paymentId)->first();

        $this->assertNotNull($payment);
        $this->assertSame($paymentId, $payment->id);
        $this->assertSame($this->orderId, $payment->pedido_id);
        $this->assertSame($this->debitMethodId, $payment->metodo_pago_id);
        $this->assertSame($this->cashSessionId, $payment->sesion_caja_id);
        $this->assertSame($this->userId, $payment->usuario_id);
        $this->assertSame(self::UUID_ONE, $payment->clave_idempotencia);
        $this->assertSame('cobro', $payment->tipo);
        $this->assertSame('1.00', $payment->monto);
        $this->assertNull($payment->monto_recibido);
        $this->assertNull($payment->vuelto);
        $this->assertNotNull($payment->ocurrido_en);
        $this->assertNull($payment->observacion);
        $this->assertNotNull($payment->created_at);
        $this->assertFalse(property_exists($payment, 'updated_at'));
    }

    #[DataProvider('validOrderPaymentTypes')]
    public function test_valid_order_payment_type_is_accepted(string $type): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['tipo' => $type],
        );

        $this->assertSame($type, DB::table('pagos_pedido')->where('id', $paymentId)->value('tipo'));
    }

    public static function validOrderPaymentTypes(): array
    {
        return [
            'charge' => ['cobro'],
            'refund' => ['devolucion'],
        ];
    }

    #[DataProvider('invalidOrderPaymentTypes')]
    public function test_invalid_order_payment_type_is_rejected(string $type): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['tipo' => $type],
        );
    }

    public static function invalidOrderPaymentTypes(): array
    {
        return [
            'empty' => [''],
            'capitalized' => ['Cobro'],
            'uppercase' => ['COBRO'],
            'accented refund' => ['devolución'],
            'payment' => ['pago'],
            'income' => ['ingreso'],
            'other' => ['otro'],
        ];
    }

    #[DataProvider('validOrderPaymentAmounts')]
    public function test_valid_order_payment_amount_is_accepted(string $amount): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['monto' => $amount],
        );

        $this->assertSame($amount, DB::table('pagos_pedido')->where('id', $paymentId)->value('monto'));
    }

    public static function validOrderPaymentAmounts(): array
    {
        return [
            'small positive' => ['0.01'],
            'positive amount' => ['1000.00'],
            'maximum numeric value' => ['999999999999.99'],
        ];
    }

    #[DataProvider('invalidOrderPaymentAmounts')]
    public function test_invalid_order_payment_amount_is_rejected(string $amount): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['monto' => $amount],
        );
    }

    public static function invalidOrderPaymentAmounts(): array
    {
        return [
            'zero' => ['0.00'],
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('invalidReceivedAmounts')]
    public function test_invalid_received_amount_is_rejected(string $receivedAmount): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            [
                'monto' => '1.00',
                'monto_recibido' => $receivedAmount,
                'vuelto' => '0.00',
            ],
        );
    }

    public static function invalidReceivedAmounts(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('invalidChangeAmounts')]
    public function test_invalid_change_amount_is_rejected(string $changeAmount): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            [
                'monto' => '1.00',
                'monto_recibido' => '1.00',
                'vuelto' => $changeAmount,
            ],
        );
    }

    public static function invalidChangeAmounts(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_non_cash_charge_with_null_cash_fields_is_allowed(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['monto_recibido' => null, 'vuelto' => null],
        );
        $payment = DB::table('pagos_pedido')->where('id', $paymentId)->first();

        $this->assertSame($this->debitMethodId, $payment->metodo_pago_id);
        $this->assertNull($payment->monto_recibido);
        $this->assertNull($payment->vuelto);
    }

    public function test_cash_charge_without_change_is_allowed(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            [
                'monto' => '1000.00',
                'monto_recibido' => '1000.00',
                'vuelto' => '0.00',
            ],
        );
        $payment = DB::table('pagos_pedido')->where('id', $paymentId)->first();

        $this->assertSame('1000.00', $payment->monto);
        $this->assertSame('1000.00', $payment->monto_recibido);
        $this->assertSame('0.00', $payment->vuelto);
    }

    public function test_cash_charge_with_change_is_allowed(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            [
                'monto' => '1000.00',
                'monto_recibido' => '2000.00',
                'vuelto' => '1000.00',
            ],
        );
        $payment = DB::table('pagos_pedido')->where('id', $paymentId)->first();

        $this->assertSame('1000.00', $payment->monto);
        $this->assertSame('2000.00', $payment->monto_recibido);
        $this->assertSame('1000.00', $payment->vuelto);
    }

    #[DataProvider('invalidCashFieldCombinations')]
    public function test_inconsistent_cash_field_combination_is_rejected(
        string $type,
        string $amount,
        ?string $receivedAmount,
        ?string $changeAmount,
    ): void {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            [
                'tipo' => $type,
                'monto' => $amount,
                'monto_recibido' => $receivedAmount,
                'vuelto' => $changeAmount,
            ],
        );
    }

    public static function invalidCashFieldCombinations(): array
    {
        return [
            'received without change' => ['cobro', '1000.00', '1000.00', null],
            'change without received' => ['cobro', '1000.00', null, '0.00'],
            'zero received below positive amount' => ['cobro', '0.01', '0.00', '0.00'],
            'incorrect change' => ['cobro', '1000.00', '2000.00', '999.99'],
            'refund with received only' => ['devolucion', '1000.00', '1000.00', null],
            'refund with change only' => ['devolucion', '1000.00', null, '0.00'],
            'refund with both cash fields' => ['devolucion', '1000.00', '1000.00', '0.00'],
        ];
    }

    public function test_valid_idempotency_uuid_is_accepted(): void
    {
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            $uuid,
        );

        $this->assertSame($uuid, DB::table('pagos_pedido')->where('id', $paymentId)->value('clave_idempotencia'));
    }

    public function test_duplicate_idempotency_key_is_rejected(): void
    {
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
        );

        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
        );
    }

    public function test_distinct_idempotency_keys_are_accepted(): void
    {
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
        );
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_TWO,
        );

        $keys = DB::table('pagos_pedido')->orderBy('clave_idempotencia')->pluck('clave_idempotencia')->all();
        $this->assertCount(2, $keys);
        $this->assertSame([self::UUID_ONE, self::UUID_TWO], $keys);
    }

    public function test_invalid_idempotency_uuid_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            'not-a-uuid',
        );
    }

    #[DataProvider('validOrderPaymentObservations')]
    public function test_valid_order_payment_observation_is_preserved(?string $observation): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['observacion' => $observation],
        );

        $this->assertSame(
            $observation,
            DB::table('pagos_pedido')->where('id', $paymentId)->value('observacion'),
        );
    }

    public static function validOrderPaymentObservations(): array
    {
        return [
            'null' => [null],
            'text' => ['Pago registrado en caja'],
        ];
    }

    #[DataProvider('invalidOrderPaymentObservations')]
    public function test_invalid_order_payment_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['observacion' => $observation],
        );
    }

    public static function invalidOrderPaymentObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Pago registrado en caja'],
            'trailing space' => ['Pago registrado en caja '],
        ];
    }

    public function test_payment_method_code_and_visible_name_do_not_control_cash_fields(): void
    {
        $this->createOrderPayment(
            $this->orderId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['monto_recibido' => null, 'vuelto' => null],
        );
        $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_TWO,
            ['monto_recibido' => '1.00', 'vuelto' => '0.00'],
        );
        $this->createOrderPayment(
            $this->orderId,
            $this->creditMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_THREE,
            ['monto_recibido' => null, 'vuelto' => null],
        );

        $methods = DB::table('metodos_pago')
            ->whereIn('id', [$this->effectiveMethodId, $this->debitMethodId, $this->creditMethodId])
            ->orderBy('codigo')
            ->get(['codigo', 'nombre'])
            ->map(static fn (object $method): array => (array) $method)
            ->all();

        $this->assertSame([
            ['codigo' => 'credito', 'nombre' => 'Crédito'],
            ['codigo' => 'debito', 'nombre' => 'Débito'],
            ['codigo' => 'efectivo', 'nombre' => 'Efectivo'],
        ], $methods);
        $this->assertSame(3, DB::table('pagos_pedido')->count());
        $this->assertSame(1, DB::table('pagos_pedido')->whereNotNull('monto_recibido')->count());
        $this->assertSame(2, DB::table('pagos_pedido')->whereNull('monto_recibido')->count());
    }

    public function test_refund_without_prior_charge_is_structurally_allowed(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['tipo' => 'devolucion', 'monto' => '5000.00'],
        );

        $this->assertSame(0, DB::table('pagos_pedido')->where('tipo', 'cobro')->count());
        $this->assertSame(1, DB::table('pagos_pedido')->where('id', $paymentId)->where('tipo', 'devolucion')->count());
    }

    public function test_payment_can_reference_closed_cash_session(): void
    {
        $closedSessionId = $this->createCashSession($this->cashRegisterId, $this->userId, [
            'usuario_cierre_id' => $this->userId,
            'abierta_en' => '2026-09-21 08:00:00-03',
            'cerrada_en' => '2026-09-21 16:00:00-03',
            'monto_cierre_declarado' => '100.00',
            'monto_teorico_cierre' => '100.00',
        ]);
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $closedSessionId,
            $this->userId,
            self::UUID_ONE,
        );

        $this->assertNotNull(DB::table('sesiones_caja')->where('id', $closedSessionId)->value('cerrada_en'));
        $this->assertSame($closedSessionId, DB::table('pagos_pedido')->where('id', $paymentId)->value('sesion_caja_id'));
    }

    public function test_order_payment_can_exceed_order_line_total_at_database_level(): void
    {
        $articleId = $this->createArticle(
            $this->createArticleCategory(),
            $this->createUnitOfMeasure(),
        );
        $detailId = $this->createOrderDetail($this->orderId, $articleId, [
            'cantidad' => '1.000',
            'precio_unitario' => '1000.00',
        ]);
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::UUID_ONE,
            ['monto' => '1500.00'],
        );

        $exceedsTotal = DB::scalar(<<<'SQL'
            SELECT payment.monto > detail.cantidad * detail.precio_unitario
              FROM pagos_pedido AS payment
              JOIN detalle_pedidos AS detail ON detail.pedido_id = payment.pedido_id
             WHERE payment.id = ? AND detail.id = ?
            SQL, [$paymentId, $detailId]);

        $this->assertTrue($this->postgresBoolean($exceedsTotal));
        $this->assertSame('1500.00', DB::table('pagos_pedido')->where('id', $paymentId)->value('monto'));
    }

    private function postgresBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
