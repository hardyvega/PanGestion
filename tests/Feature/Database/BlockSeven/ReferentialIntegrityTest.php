<?php

namespace Tests\Feature\Database\BlockSeven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const PAYMENT_UUID = '11111111-1111-4111-8111-111111111111';

    private int $articleId;

    private int $cashSessionId;

    private int $categoryId;

    private int $clientId;

    private int $creatorId;

    private int $orderId;

    private int $paymentMethodId;

    private int $roleId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->creatorId = $this->createUser($this->roleId);
        $this->clientId = $this->createClient();
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->paymentMethodId = $this->createPaymentMethod([
            'codigo' => 'debito',
            'nombre' => 'Débito',
        ]);
        $cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($cashRegisterId, $this->creatorId);
        $this->orderId = $this->createOrder($this->clientId, $this->creatorId);
    }

    public function test_order_rejects_missing_client(): void
    {
        $missingClientId = $this->createClient(['nombre' => 'Cliente eliminado']);
        $this->assertSame(1, DB::table('clientes')->where('id', $missingClientId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrder($missingClientId, $this->creatorId);
    }

    public function test_order_rejects_missing_creator(): void
    {
        $missingCreatorId = $this->createDistinctUser('creador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingCreatorId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrder($this->clientId, $missingCreatorId);
    }

    public function test_order_detail_rejects_missing_order(): void
    {
        $missingOrderId = $this->createOrder($this->clientId, $this->creatorId);
        $this->assertSame(1, DB::table('pedidos')->where('id', $missingOrderId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrderDetail($missingOrderId, $this->articleId);
    }

    public function test_order_detail_rejects_missing_article(): void
    {
        $missingArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'articulo_eliminado',
            'nombre' => 'Artículo eliminado',
        ]);
        $this->assertSame(1, DB::table('articulos')->where('id', $missingArticleId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrderDetail($this->orderId, $missingArticleId);
    }

    public function test_order_payment_rejects_missing_order(): void
    {
        $missingOrderId = $this->createOrder($this->clientId, $this->creatorId);
        $this->assertSame(1, DB::table('pedidos')->where('id', $missingOrderId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $missingOrderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->creatorId,
            self::PAYMENT_UUID,
        );
    }

    public function test_order_payment_rejects_missing_payment_method(): void
    {
        $missingMethodId = $this->createPaymentMethod([
            'codigo' => 'metodo_eliminado',
            'nombre' => 'Método eliminado',
        ]);
        $this->assertSame(1, DB::table('metodos_pago')->where('id', $missingMethodId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $missingMethodId,
            $this->cashSessionId,
            $this->creatorId,
            self::PAYMENT_UUID,
        );
    }

    public function test_order_payment_rejects_missing_cash_session(): void
    {
        $missingSessionId = $this->cashSessionId;
        $this->assertSame(1, DB::table('sesiones_caja')->where('id', $missingSessionId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $missingSessionId,
            $this->creatorId,
            self::PAYMENT_UUID,
        );
    }

    public function test_order_payment_rejects_missing_user(): void
    {
        $missingUserId = $this->createDistinctUser('pago_usuario_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->expectException(QueryException::class);
        $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $missingUserId,
            self::PAYMENT_UUID,
        );
    }

    public function test_client_with_order_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);
        DB::table('clientes')->where('id', $this->clientId)->delete();
    }

    public function test_creator_with_order_cannot_be_deleted(): void
    {
        $orderCreatorId = $this->createDistinctUser('creador_pedido');
        $this->createOrder($this->clientId, $orderCreatorId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $orderCreatorId)->delete();
    }

    public function test_order_with_detail_cannot_be_deleted(): void
    {
        $this->createOrderDetail($this->orderId, $this->articleId);

        $this->expectException(QueryException::class);
        DB::table('pedidos')->where('id', $this->orderId)->delete();
    }

    public function test_article_with_order_detail_cannot_be_deleted(): void
    {
        $this->createOrderDetail($this->orderId, $this->articleId);

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $this->articleId)->delete();
    }

    public function test_order_with_payment_cannot_be_deleted(): void
    {
        $this->createPaymentFixture();

        $this->expectException(QueryException::class);
        DB::table('pedidos')->where('id', $this->orderId)->delete();
    }

    public function test_payment_method_with_order_payment_cannot_be_deleted(): void
    {
        $this->createPaymentFixture();

        $this->expectException(QueryException::class);
        DB::table('metodos_pago')->where('id', $this->paymentMethodId)->delete();
    }

    public function test_cash_session_with_order_payment_cannot_be_deleted(): void
    {
        $this->createPaymentFixture();

        $this->expectException(QueryException::class);
        DB::table('sesiones_caja')->where('id', $this->cashSessionId)->delete();
    }

    public function test_user_with_order_payment_cannot_be_deleted(): void
    {
        $paymentUserId = $this->createDistinctUser('usuario_pago');
        $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $paymentUserId,
            self::PAYMENT_UUID,
        );

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $paymentUserId)->delete();
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Pedidos',
            'nombre_usuario' => $username,
        ]);
    }

    private function createPaymentFixture(): int
    {
        return $this->createOrderPayment(
            $this->orderId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->creatorId,
            self::PAYMENT_UUID,
        );
    }
}
