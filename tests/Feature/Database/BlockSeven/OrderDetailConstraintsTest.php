<?php

namespace Tests\Feature\Database\BlockSeven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class OrderDetailConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $clientId;

    private int $creatorId;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = $this->createClient();
        $this->creatorId = $this->createUser($this->createRole());
        $this->articleId = $this->createArticle(
            $this->createArticleCategory(),
            $this->createUnitOfMeasure(),
        );
        $this->orderId = $this->createOrder($this->clientId, $this->creatorId);
    }

    public function test_order_detail_can_be_created_with_minimum_data_and_defaults(): void
    {
        $detailId = $this->createOrderDetail($this->orderId, $this->articleId);
        $detail = DB::table('detalle_pedidos')->where('id', $detailId)->first();

        $this->assertNotNull($detail);
        $this->assertSame($detailId, $detail->id);
        $this->assertSame($this->orderId, $detail->pedido_id);
        $this->assertSame($this->articleId, $detail->articulo_id);
        $this->assertSame('1.000', $detail->cantidad);
        $this->assertSame('1000.00', $detail->precio_unitario);
        $this->assertNull($detail->observacion);
        $this->assertNotNull($detail->created_at);
        $this->assertNotNull($detail->updated_at);
    }

    #[DataProvider('validOrderDetailQuantities')]
    public function test_valid_order_detail_quantity_is_accepted(string $quantity, string $storedQuantity): void
    {
        $detailId = $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['cantidad' => $quantity],
        );

        $this->assertSame(
            $storedQuantity,
            DB::table('detalle_pedidos')->where('id', $detailId)->value('cantidad'),
        );
    }

    public static function validOrderDetailQuantities(): array
    {
        return [
            'smallest scale value' => ['0.001', '0.001'],
            'integer value' => ['1', '1.000'],
            'maximum numeric value' => ['99999999999.999', '99999999999.999'],
        ];
    }

    #[DataProvider('invalidOrderDetailQuantities')]
    public function test_invalid_order_detail_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['cantidad' => $quantity],
        );
    }

    public static function invalidOrderDetailQuantities(): array
    {
        return [
            'zero' => ['0'],
            'small negative' => ['-0.001'],
            'negative integer' => ['-1'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_order_detail_accepts_zero_unit_price(): void
    {
        $detailId = $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['precio_unitario' => '0.00'],
        );

        $this->assertSame('0.00', DB::table('detalle_pedidos')->where('id', $detailId)->value('precio_unitario'));
    }

    #[DataProvider('validPositiveOrderDetailUnitPrices')]
    public function test_valid_positive_order_detail_unit_price_is_accepted(string $price): void
    {
        $detailId = $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['precio_unitario' => $price],
        );

        $this->assertSame($price, DB::table('detalle_pedidos')->where('id', $detailId)->value('precio_unitario'));
    }

    public static function validPositiveOrderDetailUnitPrices(): array
    {
        return [
            'small positive' => ['0.01'],
            'positive amount' => ['1500.00'],
        ];
    }

    #[DataProvider('invalidOrderDetailUnitPrices')]
    public function test_invalid_order_detail_unit_price_is_rejected(string $price): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['precio_unitario' => $price],
        );
    }

    public static function invalidOrderDetailUnitPrices(): array
    {
        return [
            'small negative' => ['-0.01'],
            'negative amount' => ['-1.00'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validOrderDetailObservations')]
    public function test_valid_order_detail_observation_is_preserved(?string $observation): void
    {
        $detailId = $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['observacion' => $observation],
        );

        $this->assertSame(
            $observation,
            DB::table('detalle_pedidos')->where('id', $detailId)->value('observacion'),
        );
    }

    public static function validOrderDetailObservations(): array
    {
        return [
            'null' => [null],
            'text' => ['Sin semillas'],
        ];
    }

    #[DataProvider('invalidOrderDetailObservations')]
    public function test_invalid_order_detail_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['observacion' => $observation],
        );
    }

    public static function invalidOrderDetailObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Sin semillas'],
            'trailing space' => ['Sin semillas '],
        ];
    }

    public function test_same_article_can_appear_in_multiple_lines_of_same_order(): void
    {
        $firstDetailId = $this->createOrderDetail($this->orderId, $this->articleId);
        $secondDetailId = $this->createOrderDetail(
            $this->orderId,
            $this->articleId,
            ['cantidad' => '2.000', 'observacion' => 'Segunda línea'],
        );

        $this->assertNotSame($firstDetailId, $secondDetailId);
        $this->assertSame(
            2,
            DB::table('detalle_pedidos')
                ->where('pedido_id', $this->orderId)
                ->where('articulo_id', $this->articleId)
                ->count(),
        );
    }

    public function test_confirmed_order_with_zero_stock_detail_is_structurally_allowed(): void
    {
        $confirmedOrderId = $this->createOrder(
            $this->clientId,
            $this->creatorId,
            ['estado' => 'confirmado'],
        );
        $detailId = $this->createOrderDetail(
            $confirmedOrderId,
            $this->articleId,
            ['cantidad' => '5.000'],
        );

        $this->assertSame('0.000', DB::table('articulos')->where('id', $this->articleId)->value('stock_actual'));
        $this->assertSame(1, DB::table('detalle_pedidos')->where('id', $detailId)->count());
    }
}
