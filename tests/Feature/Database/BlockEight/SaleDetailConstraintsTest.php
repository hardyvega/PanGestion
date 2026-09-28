<?php

namespace Tests\Feature\Database\BlockEight;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class SaleDetailConstraintsTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const SALE_UUID = '11111111-1111-4111-8111-111111111111';

    private int $articleId;

    private int $categoryId;

    private int $saleId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $userId = $this->createUser($this->createRole());
        $cashSessionId = $this->createCashSession($this->createCashRegister(), $userId);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->saleId = $this->createSale($userId, $cashSessionId, self::SALE_UUID);
    }

    public function test_sale_detail_can_be_created_with_minimum_data_and_defaults(): void
    {
        $detailId = $this->createSaleDetail($this->saleId, $this->articleId);
        $detail = DB::table('detalle_ventas')->where('id', $detailId)->first();

        $this->assertNotNull($detail);
        $this->assertSame($this->saleId, $detail->venta_id);
        $this->assertSame($this->articleId, $detail->articulo_id);
        $this->assertSame('pan_prueba', $detail->articulo_sku_snapshot);
        $this->assertSame('Pan de prueba', $detail->articulo_nombre_snapshot);
        $this->assertSame('kg', $detail->unidad_codigo_snapshot);
        $this->assertSame('kg', $detail->unidad_simbolo_snapshot);
        $this->assertSame('1.000', $detail->cantidad);
        $this->assertSame('1000.00', $detail->precio_unitario);
        $this->assertSame('1000.00', $detail->subtotal);
        $this->assertNull($detail->observacion);
        $this->assertNotNull($detail->created_at);
    }

    #[DataProvider('invalidSkuSnapshots')]
    public function test_invalid_sku_snapshot_is_rejected(string $sku): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['articulo_sku_snapshot' => $sku],
        );
    }

    public static function invalidSkuSnapshots(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' pan_prueba'],
            'trailing space' => ['pan_prueba '],
            'uppercase' => ['PAN_PRUEBA'],
            'invalid format' => ['pan.prueba'],
        ];
    }

    #[DataProvider('invalidNameSnapshots')]
    public function test_invalid_name_snapshot_is_rejected(string $name): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['articulo_nombre_snapshot' => $name],
        );
    }

    public static function invalidNameSnapshots(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Pan de prueba'],
            'trailing space' => ['Pan de prueba '],
        ];
    }

    #[DataProvider('invalidUnitCodeSnapshots')]
    public function test_invalid_unit_code_snapshot_is_rejected(string $code): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['unidad_codigo_snapshot' => $code],
        );
    }

    public static function invalidUnitCodeSnapshots(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' kg'],
            'trailing space' => ['kg '],
            'uppercase' => ['KG'],
            'invalid format' => ['1kg'],
        ];
    }

    #[DataProvider('invalidUnitSymbolSnapshots')]
    public function test_invalid_unit_symbol_snapshot_is_rejected(string $symbol): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['unidad_simbolo_snapshot' => $symbol],
        );
    }

    public static function invalidUnitSymbolSnapshots(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Kg'],
            'trailing space' => ['Kg '],
        ];
    }

    public function test_unit_symbol_snapshot_preserves_visible_capitalization(): void
    {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['unidad_simbolo_snapshot' => 'Kg'],
        );

        $this->assertSame('Kg', DB::table('detalle_ventas')->where('id', $detailId)->value('unidad_simbolo_snapshot'));
    }

    #[DataProvider('validQuantities')]
    public function test_positive_and_fractional_quantities_are_accepted(
        string $quantity,
        string $subtotal,
        string $storedQuantity,
    ): void {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['cantidad' => $quantity, 'subtotal' => $subtotal],
        );

        $this->assertSame(
            $storedQuantity,
            DB::table('detalle_ventas')->where('id', $detailId)->value('cantidad'),
        );
    }

    public static function validQuantities(): array
    {
        return [
            'positive integer' => ['2.000', '2000.00', '2.000'],
            'fractional' => ['0.125', '125.00', '0.125'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['cantidad' => $quantity],
        );
    }

    public static function invalidQuantities(): array
    {
        return [
            'zero' => ['0.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_zero_unit_price_is_accepted(): void
    {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['precio_unitario' => '0.00', 'subtotal' => '0.00'],
        );

        $this->assertSame('0.00', DB::table('detalle_ventas')->where('id', $detailId)->value('precio_unitario'));
    }

    #[DataProvider('invalidUnitPrices')]
    public function test_invalid_unit_price_is_rejected(string $price): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['precio_unitario' => $price],
        );
    }

    public static function invalidUnitPrices(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_exact_subtotal_is_accepted(): void
    {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['cantidad' => '2.500', 'precio_unitario' => '4.00', 'subtotal' => '10.00'],
        );

        $this->assertSame('10.00', DB::table('detalle_ventas')->where('id', $detailId)->value('subtotal'));
    }

    public function test_subtotal_with_real_numeric_rounding_is_accepted(): void
    {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['cantidad' => '0.125', 'precio_unitario' => '10.04', 'subtotal' => '1.26'],
        );

        $this->assertSame('1.26', DB::table('detalle_ventas')->where('id', $detailId)->value('subtotal'));
    }

    #[DataProvider('invalidSubtotals')]
    public function test_invalid_subtotal_is_rejected(
        string $quantity,
        string $price,
        string $subtotal,
    ): void {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['cantidad' => $quantity, 'precio_unitario' => $price, 'subtotal' => $subtotal],
        );
    }

    public static function invalidSubtotals(): array
    {
        return [
            'rounded result lower by one cent' => ['0.125', '10.04', '1.25'],
            'ordinary result higher by one cent' => ['2.500', '4.00', '10.01'],
            'negative' => ['1.000', '0.00', '-0.01'],
            'NaN' => ['1.000', '1.00', 'NaN'],
        ];
    }

    public function test_valid_sale_detail_observation_is_preserved(): void
    {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['observacion' => 'Sin cortar'],
        );

        $this->assertSame('Sin cortar', DB::table('detalle_ventas')->where('id', $detailId)->value('observacion'));
    }

    #[DataProvider('invalidSaleDetailObservations')]
    public function test_invalid_sale_detail_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['observacion' => $observation],
        );
    }

    public static function invalidSaleDetailObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Sin cortar'],
            'trailing space' => ['Sin cortar '],
        ];
    }

    public function test_same_article_can_appear_in_multiple_lines_of_same_sale(): void
    {
        $firstId = $this->createSaleDetail($this->saleId, $this->articleId);
        $secondId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['observacion' => 'Segunda linea'],
        );

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(
            2,
            DB::table('detalle_ventas')
                ->where('venta_id', $this->saleId)
                ->where('articulo_id', $this->articleId)
                ->count(),
        );
    }

    public function test_inactive_article_is_structurally_allowed_in_sale_detail(): void
    {
        $articleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_inactivo',
            'nombre' => 'Pan inactivo',
            'activo' => false,
        ]);
        $detailId = $this->createSaleDetail($this->saleId, $articleId, [
            'articulo_sku_snapshot' => 'pan_inactivo',
            'articulo_nombre_snapshot' => 'Pan inactivo',
        ]);

        $this->assertFalse((bool) DB::table('articulos')->where('id', $articleId)->value('activo'));
        $this->assertSame($articleId, DB::table('detalle_ventas')->where('id', $detailId)->value('articulo_id'));
    }

    public function test_sale_detail_price_can_differ_from_current_article_price(): void
    {
        $articleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_precio_distinto',
            'nombre' => 'Pan precio distinto',
            'precio_venta_actual' => '5000.00',
        ]);
        $detailId = $this->createSaleDetail($this->saleId, $articleId, [
            'articulo_sku_snapshot' => 'pan_precio_distinto',
            'articulo_nombre_snapshot' => 'Pan precio distinto',
            'precio_unitario' => '1000.00',
            'subtotal' => '1000.00',
        ]);

        $this->assertSame('5000.00', DB::table('articulos')->where('id', $articleId)->value('precio_venta_actual'));
        $this->assertSame('1000.00', DB::table('detalle_ventas')->where('id', $detailId)->value('precio_unitario'));
    }

    public function test_insufficient_article_stock_is_structurally_allowed(): void
    {
        $articleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_sin_stock',
            'nombre' => 'Pan sin stock',
        ]);
        $detailId = $this->createSaleDetail($this->saleId, $articleId, [
            'articulo_sku_snapshot' => 'pan_sin_stock',
            'articulo_nombre_snapshot' => 'Pan sin stock',
            'cantidad' => '10.000',
            'subtotal' => '10000.00',
        ]);

        $this->assertSame('0.000', DB::table('articulos')->where('id', $articleId)->value('stock_actual'));
        $this->assertSame('10.000', DB::table('detalle_ventas')->where('id', $detailId)->value('cantidad'));
    }

    public function test_sale_total_can_differ_from_sum_of_details(): void
    {
        $detailId = $this->createSaleDetail(
            $this->saleId,
            $this->articleId,
            ['precio_unitario' => '500.00', 'subtotal' => '500.00'],
        );

        $this->assertSame('1000.00', DB::table('ventas')->where('id', $this->saleId)->value('total'));
        $this->assertSame('500.00', DB::table('detalle_ventas')->where('id', $detailId)->value('subtotal'));
    }
}
