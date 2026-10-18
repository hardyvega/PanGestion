<?php

namespace Tests\Feature\Database\BlockSix;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class CashMovementConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockEightRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $roleId;

    private int $cashSessionId;

    private int $userId;

    private int $orderId;

    private int $saleId;

    private int $paymentMethodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $cashRegisterId = $this->createCashRegister();
        $this->userId = $this->createUser($this->roleId);
        $this->cashSessionId = $this->createCashSession($cashRegisterId, $this->userId);
        $clientId = $this->createClient();
        $this->orderId = $this->createOrder($clientId, $this->userId);
        $this->saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        );
        $this->paymentMethodId = $this->createPaymentMethod();
    }

    public function test_cash_movement_can_be_created_with_minimum_data_and_defaults(): void
    {
        $movementId = $this->createCashMovement($this->cashSessionId, $this->userId, [
            'concepto' => 'Ingreso manual',
        ]);
        $movement = DB::table('movimientos_caja')->where('id', $movementId)->first();

        $this->assertNotNull($movement);
        $this->assertSame($movementId, $movement->id);
        $this->assertSame($this->cashSessionId, $movement->sesion_caja_id);
        $this->assertSame($this->userId, $movement->usuario_id);
        $this->assertSame('ingreso', $movement->tipo_movimiento);
        $this->assertSame('1.00', $movement->monto);
        $this->assertSame('Ingreso manual', $movement->concepto);
        $this->assertNotNull($movement->ocurrido_en);
        $this->assertNotNull($movement->created_at);
        $this->assertFalse(property_exists($movement, 'updated_at'));
    }

    #[DataProvider('validCashMovementTypes')]
    public function test_valid_cash_movement_type_is_accepted(string $type): void
    {
        $movementId = $this->createCashMovement(
            $this->cashSessionId,
            $this->userId,
            ['tipo_movimiento' => $type],
        );

        $this->assertSame(
            $type,
            DB::table('movimientos_caja')->where('id', $movementId)->value('tipo_movimiento'),
        );
    }

    public static function validCashMovementTypes(): array
    {
        return [
            'income' => ['ingreso'],
            'expense' => ['egreso'],
            'withdrawal' => ['retiro'],
        ];
    }

    #[DataProvider('invalidCashMovementTypes')]
    public function test_invalid_cash_movement_type_is_rejected(string $type): void
    {
        $this->expectException(QueryException::class);
        $this->createCashMovement(
            $this->cashSessionId,
            $this->userId,
            ['tipo_movimiento' => $type],
        );
    }

    public static function invalidCashMovementTypes(): array
    {
        return [
            'empty' => [''],
            'charge' => ['cobro'],
            'refund' => ['devolucion'],
            'uppercase' => ['Ingreso'],
            'other' => ['otro'],
        ];
    }

    #[DataProvider('validCashMovementAmounts')]
    public function test_valid_cash_movement_amount_is_accepted(string $amount): void
    {
        $movementId = $this->createCashMovement(
            $this->cashSessionId,
            $this->userId,
            ['monto' => $amount],
        );

        $this->assertSame($amount, DB::table('movimientos_caja')->where('id', $movementId)->value('monto'));
    }

    public static function validCashMovementAmounts(): array
    {
        return [
            'small positive' => ['0.01'],
            'positive' => ['150.00'],
        ];
    }

    #[DataProvider('invalidCashMovementAmounts')]
    public function test_invalid_cash_movement_amount_is_rejected(string $amount): void
    {
        $this->expectException(QueryException::class);
        $this->createCashMovement(
            $this->cashSessionId,
            $this->userId,
            ['monto' => $amount],
        );
    }

    public static function invalidCashMovementAmounts(): array
    {
        return [
            'zero' => ['0.00'],
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validCashMovementConcepts')]
    public function test_valid_cash_movement_concept_is_accepted(string $concept): void
    {
        $movementId = $this->createCashMovement(
            $this->cashSessionId,
            $this->userId,
            ['concepto' => $concept],
        );

        $this->assertSame(
            $concept,
            DB::table('movimientos_caja')->where('id', $movementId)->value('concepto'),
        );
    }

    public static function validCashMovementConcepts(): array
    {
        return [
            'bag purchase' => ['Compra de bolsas'],
            'deposit withdrawal' => ['Retiro para depósito'],
        ];
    }

    #[DataProvider('invalidCashMovementConcepts')]
    public function test_invalid_cash_movement_concept_is_rejected(string $concept): void
    {
        $this->expectException(QueryException::class);
        $this->createCashMovement(
            $this->cashSessionId,
            $this->userId,
            ['concepto' => $concept],
        );
    }

    public static function invalidCashMovementConcepts(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Compra de bolsas'],
            'trailing space' => ['Compra de bolsas '],
        ];
    }

    public function test_cash_movement_rejects_missing_session(): void
    {
        $missingSessionId = ((int) DB::table('sesiones_caja')->max('id')) + 1;

        $this->expectException(QueryException::class);
        $this->createCashMovement($missingSessionId, $this->userId);
    }

    public function test_cash_movement_rejects_missing_user(): void
    {
        $missingUserId = $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Movimiento',
            'nombre_usuario' => 'movimiento_usuario',
        ]);
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->expectException(QueryException::class);
        $this->createCashMovement($this->cashSessionId, $missingUserId);
    }

    public function test_manual_cash_movement_allows_both_payment_sources_to_be_null(): void
    {
        $movementId = $this->createCashMovement($this->cashSessionId, $this->userId);
        $movement = DB::table('movimientos_caja')->where('id', $movementId)->first();

        $this->assertNull($movement->pago_venta_id);
        $this->assertNull($movement->pago_pedido_id);
    }

    public function test_cash_movement_accepts_exactly_one_order_payment_source(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        );
        $movementId = $this->createCashMovement($this->cashSessionId, $this->userId, [
            'tipo_movimiento' => 'egreso',
            'pago_pedido_id' => $paymentId,
        ]);

        $this->assertSame($paymentId, DB::table('movimientos_caja')->where('id', $movementId)->value('pago_pedido_id'));
    }

    public function test_cash_movement_accepts_exactly_one_sale_payment_source(): void
    {
        $paymentId = $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        );
        $movementId = $this->createCashMovement($this->cashSessionId, $this->userId, [
            'pago_venta_id' => $paymentId,
        ]);

        $this->assertSame($paymentId, DB::table('movimientos_caja')->where('id', $movementId)->value('pago_venta_id'));
    }

    public function test_cash_movement_rejects_both_payment_sources(): void
    {
        $orderPaymentId = $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        );
        $salePaymentId = $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
        );

        $this->expectException(QueryException::class);
        $this->createCashMovement($this->cashSessionId, $this->userId, [
            'pago_venta_id' => $salePaymentId,
            'pago_pedido_id' => $orderPaymentId,
        ]);
    }

    public function test_withdrawal_cash_movement_rejects_payment_source(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
        );

        $this->expectException(QueryException::class);
        $this->createCashMovement($this->cashSessionId, $this->userId, [
            'tipo_movimiento' => 'retiro',
            'pago_pedido_id' => $paymentId,
        ]);
    }

    public function test_same_payment_cannot_cause_two_cash_movements(): void
    {
        $paymentId = $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            '11111111-1111-4111-8111-111111111111',
        );
        $this->createCashMovement($this->cashSessionId, $this->userId, [
            'pago_pedido_id' => $paymentId,
        ]);

        $this->expectException(QueryException::class);
        $this->createCashMovement($this->cashSessionId, $this->userId, [
            'pago_pedido_id' => $paymentId,
        ]);
    }
}
