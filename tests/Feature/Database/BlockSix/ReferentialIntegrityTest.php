<?php

namespace Tests\Feature\Database\BlockSix;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockEightRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockSevenRecords;
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

    public function test_cash_register_with_session_cannot_be_deleted(): void
    {
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->expectException(QueryException::class);
        DB::table('cajas')->where('id', $this->cashRegisterId)->delete();
    }

    public function test_opening_user_with_session_cannot_be_deleted(): void
    {
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $this->openingUserId)->delete();
    }

    public function test_closing_user_with_closed_session_cannot_be_deleted(): void
    {
        $closingUserId = $this->createDistinctUser('usuario_cierre');
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, [
            'usuario_cierre_id' => $closingUserId,
            'abierta_en' => '2026-09-21 08:00:00-03',
            'cerrada_en' => '2026-09-21 16:00:00-03',
            'monto_cierre_declarado' => '100.00',
            'monto_teorico_cierre' => '100.00',
        ]);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $closingUserId)->delete();
    }

    public function test_cash_session_with_movement_cannot_be_deleted(): void
    {
        $sessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $this->createCashMovement($sessionId, $this->openingUserId);

        $this->expectException(QueryException::class);
        DB::table('sesiones_caja')->where('id', $sessionId)->delete();
    }

    public function test_user_with_cash_movement_cannot_be_deleted(): void
    {
        $movementUserId = $this->createDistinctUser('usuario_movimiento');
        $sessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $this->createCashMovement($sessionId, $movementUserId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $movementUserId)->delete();
    }

    public function test_order_payment_referenced_by_cash_movement_cannot_be_deleted(): void
    {
        $clientId = $this->createClient();
        $orderId = $this->createOrder($clientId, $this->openingUserId);
        $paymentMethodId = $this->createPaymentMethod();
        $paymentSessionId = $this->createCashSession(
            $this->createCashRegister(['codigo' => 'caja_pago_pedido', 'nombre' => 'Caja pago pedido']),
            $this->openingUserId,
        );
        $paymentId = $this->createOrderPayment(
            $orderId,
            $paymentMethodId,
            $paymentSessionId,
            $this->openingUserId,
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        );
        $this->createCashMovement($paymentSessionId, $this->openingUserId, ['pago_pedido_id' => $paymentId]);

        $this->expectException(QueryException::class);
        DB::table('pagos_pedido')->where('id', $paymentId)->delete();
    }

    public function test_sale_payment_referenced_by_cash_movement_cannot_be_deleted(): void
    {
        $paymentSessionId = $this->createCashSession(
            $this->createCashRegister(['codigo' => 'caja_pago_venta', 'nombre' => 'Caja pago venta']),
            $this->openingUserId,
        );
        $clientId = $this->createClient(['nombre' => 'Cliente venta']);
        $saleId = $this->createSale(
            $this->openingUserId,
            $paymentSessionId,
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            ['cliente_id' => $clientId],
        );
        $paymentId = $this->createSalePayment(
            $saleId,
            $this->createPaymentMethod(['codigo' => 'pago_venta', 'nombre' => 'Pago venta']),
            $paymentSessionId,
            $this->openingUserId,
            'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        );
        $this->createCashMovement($paymentSessionId, $this->openingUserId, ['pago_venta_id' => $paymentId]);

        $this->expectException(QueryException::class);
        DB::table('pagos_venta')->where('id', $paymentId)->delete();
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Caja',
            'nombre_usuario' => $username,
        ]);
    }
}
