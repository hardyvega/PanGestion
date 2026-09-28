<?php

namespace Tests\Feature\Database\BlockEight;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const PAYMENT_UUID = '11111111-1111-4111-8111-111111111111';

    private const SALE_UUID_ONE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const SALE_UUID_TWO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private int $articleId;

    private int $cashSessionId;

    private int $categoryId;

    private int $clientId;

    private int $orderId;

    private int $paymentMethodId;

    private int $roleId;

    private int $saleId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->userId = $this->createUser($this->roleId);
        $this->clientId = $this->createClient();
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->paymentMethodId = $this->createPaymentMethod();
        $cashRegisterId = $this->createCashRegister();
        $this->cashSessionId = $this->createCashSession($cashRegisterId, $this->userId);
        $this->orderId = $this->createOrder($this->clientId, $this->userId);
        $this->saleId = $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_ONE,
        );
    }

    public function test_sale_rejects_missing_user(): void
    {
        $missingUserId = $this->createDistinctUser('venta_usuario_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->expectException(QueryException::class);
        $this->createSale(
            $missingUserId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            ['numero_diario' => 2],
        );
    }

    public function test_sale_rejects_missing_cash_session(): void
    {
        $missingSessionId = $this->createDistinctCashSession('venta_eliminada');
        $this->assertSame(1, DB::table('sesiones_caja')->where('id', $missingSessionId)->delete());

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $missingSessionId,
            self::SALE_UUID_TWO,
            ['numero_diario' => 2],
        );
    }

    public function test_sale_rejects_missing_client_when_client_is_informed(): void
    {
        $missingClientId = $this->createClient(['nombre' => 'Cliente eliminado']);
        $this->assertSame(1, DB::table('clientes')->where('id', $missingClientId)->delete());

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            ['numero_diario' => 2, 'cliente_id' => $missingClientId],
        );
    }

    public function test_sale_rejects_missing_order_when_order_is_informed(): void
    {
        $missingOrderId = $this->createOrder($this->clientId, $this->userId);
        $this->assertSame(1, DB::table('pedidos')->where('id', $missingOrderId)->delete());

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            [
                'numero_diario' => 2,
                'cliente_id' => $this->clientId,
                'pedido_id' => $missingOrderId,
            ],
        );
    }

    public function test_annulled_sale_rejects_missing_annulling_user(): void
    {
        $missingUserId = $this->createDistinctUser('anulador_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->expectException(QueryException::class);
        $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            [
                'numero_diario' => 2,
                'estado' => 'anulada',
                'anulada_en' => '2026-09-24 13:00:00-03',
                'usuario_anulador_id' => $missingUserId,
                'motivo_anulacion' => 'Anulacion valida',
            ],
        );
    }

    public function test_sale_detail_rejects_missing_sale(): void
    {
        $missingSaleId = $this->createAdditionalSale();
        $this->assertSame(1, DB::table('ventas')->where('id', $missingSaleId)->delete());

        $this->expectException(QueryException::class);
        $this->createSaleDetail($missingSaleId, $this->articleId);
    }

    public function test_sale_detail_rejects_missing_article(): void
    {
        $missingArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'articulo_eliminado',
            'nombre' => 'Articulo eliminado',
        ]);
        $this->assertSame(1, DB::table('articulos')->where('id', $missingArticleId)->delete());

        $this->expectException(QueryException::class);
        $this->createSaleDetail($this->saleId, $missingArticleId);
    }

    public function test_sale_payment_rejects_missing_sale(): void
    {
        $missingSaleId = $this->createAdditionalSale();
        $this->assertSame(1, DB::table('ventas')->where('id', $missingSaleId)->delete());

        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $missingSaleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID,
        );
    }

    public function test_sale_payment_rejects_missing_payment_method(): void
    {
        $missingMethodId = $this->createPaymentMethod([
            'codigo' => 'metodo_eliminado',
            'nombre' => 'Metodo eliminado',
        ]);
        $this->assertSame(1, DB::table('metodos_pago')->where('id', $missingMethodId)->delete());

        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $missingMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID,
        );
    }

    public function test_sale_payment_rejects_missing_cash_session(): void
    {
        $missingSessionId = $this->createDistinctCashSession('pago_eliminado');
        $this->assertSame(1, DB::table('sesiones_caja')->where('id', $missingSessionId)->delete());

        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $missingSessionId,
            $this->userId,
            self::PAYMENT_UUID,
        );
    }

    public function test_sale_payment_rejects_missing_user(): void
    {
        $missingUserId = $this->createDistinctUser('pago_usuario_eliminado');
        $this->assertSame(1, DB::table('usuarios')->where('id', $missingUserId)->delete());

        $this->expectException(QueryException::class);
        $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $missingUserId,
            self::PAYMENT_UUID,
        );
    }

    public function test_user_with_sale_cannot_be_deleted(): void
    {
        $saleUserId = $this->createDistinctUser('usuario_venta');
        $this->createAdditionalSale(['usuario_id' => $saleUserId]);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $saleUserId)->delete();
    }

    public function test_cash_session_with_sale_cannot_be_deleted(): void
    {
        $saleSessionId = $this->createDistinctCashSession('sesion_venta');
        $this->createAdditionalSale(['sesion_caja_id' => $saleSessionId]);

        $this->expectException(QueryException::class);
        DB::table('sesiones_caja')->where('id', $saleSessionId)->delete();
    }

    public function test_client_with_sale_cannot_be_deleted(): void
    {
        $saleClientId = $this->createClient(['nombre' => 'Cliente con venta']);
        $this->createAdditionalSale(['cliente_id' => $saleClientId]);

        $this->expectException(QueryException::class);
        DB::table('clientes')->where('id', $saleClientId)->delete();
    }

    public function test_order_with_sale_cannot_be_deleted(): void
    {
        $this->createAdditionalSale([
            'cliente_id' => $this->clientId,
            'pedido_id' => $this->orderId,
        ]);

        $this->expectException(QueryException::class);
        DB::table('pedidos')->where('id', $this->orderId)->delete();
    }

    public function test_annulling_user_with_sale_cannot_be_deleted(): void
    {
        $annullingUserId = $this->createDistinctUser('usuario_anulador');
        $this->createAdditionalSale([
            'estado' => 'anulada',
            'anulada_en' => '2026-09-24 13:00:00-03',
            'usuario_anulador_id' => $annullingUserId,
            'motivo_anulacion' => 'Anulacion valida',
        ]);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $annullingUserId)->delete();
    }

    public function test_sale_with_detail_cannot_be_deleted(): void
    {
        $this->createSaleDetail($this->saleId, $this->articleId);

        $this->expectException(QueryException::class);
        DB::table('ventas')->where('id', $this->saleId)->delete();
    }

    public function test_article_with_sale_detail_cannot_be_deleted(): void
    {
        $this->createSaleDetail($this->saleId, $this->articleId);

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $this->articleId)->delete();
    }

    public function test_sale_with_payment_cannot_be_deleted(): void
    {
        $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID,
        );

        $this->expectException(QueryException::class);
        DB::table('ventas')->where('id', $this->saleId)->delete();
    }

    public function test_payment_method_with_sale_payment_cannot_be_deleted(): void
    {
        $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $this->userId,
            self::PAYMENT_UUID,
        );

        $this->expectException(QueryException::class);
        DB::table('metodos_pago')->where('id', $this->paymentMethodId)->delete();
    }

    public function test_cash_session_with_sale_payment_cannot_be_deleted(): void
    {
        $paymentSessionId = $this->createDistinctCashSession('sesion_pago');
        $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $paymentSessionId,
            $this->userId,
            self::PAYMENT_UUID,
        );

        $this->expectException(QueryException::class);
        DB::table('sesiones_caja')->where('id', $paymentSessionId)->delete();
    }

    public function test_user_with_sale_payment_cannot_be_deleted(): void
    {
        $paymentUserId = $this->createDistinctUser('usuario_pago');
        $this->createSalePayment(
            $this->saleId,
            $this->paymentMethodId,
            $this->cashSessionId,
            $paymentUserId,
            self::PAYMENT_UUID,
        );

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $paymentUserId)->delete();
    }

    private function createAdditionalSale(array $overrides = []): int
    {
        return $this->createSale(
            $this->userId,
            $this->cashSessionId,
            self::SALE_UUID_TWO,
            array_merge(['numero_diario' => 2], $overrides),
        );
    }

    private function createDistinctCashSession(string $suffix): int
    {
        $cashRegisterId = $this->createCashRegister([
            'codigo' => 'caja_'.$suffix,
            'nombre' => 'Caja '.$suffix,
        ]);

        return $this->createCashSession($cashRegisterId, $this->userId);
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
