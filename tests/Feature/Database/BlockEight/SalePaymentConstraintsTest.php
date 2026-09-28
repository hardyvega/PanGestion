<?php

namespace Tests\Feature\Database\BlockEight;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class SalePaymentConstraintsTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const PAYMENT_UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const PAYMENT_UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const PAYMENT_UUID_THREE = '33333333-3333-4333-8333-333333333333';

    private const SALE_UUID_ONE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const SALE_UUID_TWO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private int $cashRegisterId;

    private int $cashSessionId;

    private int $creditMethodId;

    private int $debitMethodId;

    private int $effectiveMethodId;

    private int $saleId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->createRole());
        $this->effectiveMethodId = $this->createPaymentMethod([
            'codigo' => 'efectivo',
            'nombre' => 'Efectivo',
        ]);
        $this->debitMethodId = $this->createPaymentMethod([
            'codigo' => 'debito',
            'nombre' => 'Debito',
        ]);
        $this->creditMethodId = $this->createPaymentMethod([
            'codigo' => 'credito',
            'nombre' => 'Credito',
        ]);
        $this->cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($this->cashRegisterId, $this->userId);
        $this->saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_ONE,
        );
    }

    public function test_sale_payment_can_be_created_with_minimum_data_and_defaults(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
        $payment = DB::table('pagos_venta')->where('id', $paymentId)->first();

        $this->assertNotNull($payment);
        $this->assertSame($this->saleId, $payment->venta_id);
        $this->assertSame($this->debitMethodId, $payment->metodo_pago_id);
        $this->assertSame($this->cashSessionId, $payment->sesion_caja_id);
        $this->assertSame($this->userId, $payment->usuario_id);
        $this->assertSame(self::PAYMENT_UUID_ONE, $payment->clave_idempotencia);
        $this->assertSame('cobro', $payment->tipo);
        $this->assertSame('1.00', $payment->monto);
        $this->assertNull($payment->monto_recibido);
        $this->assertNull($payment->vuelto);
        $this->assertNotNull($payment->ocurrido_en);
        $this->assertNull($payment->observacion);
        $this->assertNotNull($payment->created_at);
    }

    #[DataProvider('validPaymentTypes')]
    public function test_valid_payment_type_is_accepted(string $type): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['tipo' => $type],
        );

        $this->assertSame($type, DB::table('pagos_venta')->where('id', $paymentId)->value('tipo'));
    }

    public static function validPaymentTypes(): array
    {
        return [
            'charge' => ['cobro'],
            'refund without prior charge' => ['devolucion'],
        ];
    }

    #[DataProvider('invalidPaymentTypes')]
    public function test_invalid_payment_type_is_rejected(string $type): void
    {
        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['tipo' => $type],
        );
    }

    public static function invalidPaymentTypes(): array
    {
        return [
            'uppercase charge' => ['COBRO'],
            'uppercase refund' => ['DEVOLUCION'],
            'payment' => ['pago'],
            'empty' => [''],
        ];
    }

    public function test_positive_payment_amount_is_accepted(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['monto' => '2500.50'],
        );

        $this->assertSame('2500.50', DB::table('pagos_venta')->where('id', $paymentId)->value('monto'));
    }

    #[DataProvider('invalidPaymentAmounts')]
    public function test_invalid_payment_amount_is_rejected(string $amount): void
    {
        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['monto' => $amount],
        );
    }

    public static function invalidPaymentAmounts(): array
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
        $this->createSalePayment(
            $this->saleId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
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
        $this->createSalePayment(
            $this->saleId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
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

    public function test_charge_with_null_cash_fields_is_allowed(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
        $payment = DB::table('pagos_venta')->where('id', $paymentId)->first();

        $this->assertNull($payment->monto_recibido);
        $this->assertNull($payment->vuelto);
    }

    public function test_exact_cash_charge_with_zero_change_is_allowed(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            [
                'monto' => '5000.00',
                'monto_recibido' => '5000.00',
                'vuelto' => '0.00',
            ],
        );
        $payment = DB::table('pagos_venta')->where('id', $paymentId)->first();

        $this->assertSame('5000.00', $payment->monto_recibido);
        $this->assertSame('0.00', $payment->vuelto);
    }

    public function test_cash_charge_with_change_is_allowed(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            [
                'monto' => '3500.00',
                'monto_recibido' => '5000.00',
                'vuelto' => '1500.00',
            ],
        );
        $payment = DB::table('pagos_venta')->where('id', $paymentId)->first();

        $this->assertSame('5000.00', $payment->monto_recibido);
        $this->assertSame('1500.00', $payment->vuelto);
    }

    #[DataProvider('invalidCashFieldCombinations')]
    public function test_inconsistent_cash_field_combination_is_rejected(
        string $type,
        string $amount,
        ?string $receivedAmount,
        ?string $changeAmount,
    ): void {
        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
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

    public function test_duplicate_payment_idempotency_key_is_rejected_globally(): void
    {
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
        $secondSaleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            ['numero_diario' => 2],
        );

        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $secondSaleId,
            $this->creditMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
    }

    public function test_distinct_payment_idempotency_keys_are_accepted(): void
    {
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_TWO,
        );

        $this->assertSame(2, DB::table('pagos_venta')->distinct()->count('clave_idempotencia'));
    }

    public function test_mixed_payment_methods_are_allowed_for_same_sale(): void
    {
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
        $this->createSalePayment(
            $this->saleId,
            $this->creditMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_TWO,
        );

        $this->assertSame(2, DB::table('pagos_venta')->where('venta_id', $this->saleId)->count());
    }

    public function test_same_payment_method_can_be_repeated_for_same_sale(): void
    {
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_TWO,
        );

        $this->assertSame(
            2,
            DB::table('pagos_venta')
                ->where('venta_id', $this->saleId)
                ->where('metodo_pago_id', $this->debitMethodId)
                ->count(),
        );
    }

    public function test_payment_method_semantics_do_not_control_cash_fields(): void
    {
        $debitPaymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['monto' => '10.00', 'monto_recibido' => '10.00', 'vuelto' => '0.00'],
        );
        $cashPaymentId = $this->createSalePayment(
            $this->saleId,
            $this->effectiveMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_TWO,
        );

        $this->assertSame($this->debitMethodId, DB::table('pagos_venta')->where('id', $debitPaymentId)->value('metodo_pago_id'));
        $this->assertNull(DB::table('pagos_venta')->where('id', $cashPaymentId)->value('monto_recibido'));
    }

    public function test_payment_can_reference_closed_cash_session(): void
    {
        $closedSessionId = $this->createCashSession($this->cashRegisterId, $this->userId, [
            'abierta_en' => '2026-09-24 08:00:00-03',
            'cerrada_en' => '2026-09-24 18:00:00-03',
            'usuario_cierre_id' => $this->userId,
            'monto_cierre_declarado' => '0.00',
            'monto_teorico_cierre' => '0.00',
        ]);
        $saleId = $this->createSale(
            $this->userId,
            $closedSessionId,
            self::SALE_UUID_TWO,
            ['numero_diario' => 2],
        );
        $paymentId = $this->createSalePayment(
            $saleId,
            $this->debitMethodId,
            $closedSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
        );

        $this->assertNotNull(DB::table('sesiones_caja')->where('id', $closedSessionId)->value('cerrada_en'));
        $this->assertSame($closedSessionId, DB::table('pagos_venta')->where('id', $paymentId)->value('sesion_caja_id'));
    }

    public function test_payments_can_underpay_or_exceed_sale_total(): void
    {
        $underpaymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['monto' => '1.00'],
        );
        $secondSaleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            ['numero_diario' => 2, 'total' => '10.00'],
        );
        $overpaymentId = $this->createSalePayment(
            $secondSaleId,
            $this->creditMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_TWO,
            ['monto' => '20.00'],
        );

        $this->assertSame('1000.00', DB::table('ventas')->where('id', $this->saleId)->value('total'));
        $this->assertSame('1.00', DB::table('pagos_venta')->where('id', $underpaymentId)->value('monto'));
        $this->assertSame('10.00', DB::table('ventas')->where('id', $secondSaleId)->value('total'));
        $this->assertSame('20.00', DB::table('pagos_venta')->where('id', $overpaymentId)->value('monto'));
    }

    public function test_valid_sale_payment_observation_is_preserved(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['observacion' => 'Pago dividido'],
        );

        $this->assertSame('Pago dividido', DB::table('pagos_venta')->where('id', $paymentId)->value('observacion'));
    }

    #[DataProvider('invalidSalePaymentObservations')]
    public function test_invalid_sale_payment_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $this->debitMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID_ONE,
            ['observacion' => $observation],
        );
    }

    public static function invalidSalePaymentObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Pago dividido'],
            'trailing space' => ['Pago dividido '],
        ];
    }
}
