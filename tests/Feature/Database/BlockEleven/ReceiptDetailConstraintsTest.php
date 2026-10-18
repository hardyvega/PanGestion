<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReceiptDetailConstraintsTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private int $articleId;

    private int $categoryId;

    private int $receiptId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->createRole(), ['nombre_usuario' => 'detalle_recepcion']);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->receiptId = $this->createReceipt($this->userId, self::UUID_ONE);
    }

    public function test_receipt_detail_allows_null_quantity_and_cost_in_draft_structure(): void
    {
        $detailId = $this->createReceiptDetail($this->receiptId, $this->articleId);
        $detail = DB::table('detalle_recepciones_mercaderia')->where('id', $detailId)->first();

        $this->assertNotNull($detail);
        $this->assertNull($detail->cantidad);
        $this->assertNull($detail->costo_unitario);
        $this->assertNull($detail->motivo_costo_cero);
        $this->assertNotNull($detail->created_at);
        $this->assertNotNull($detail->updated_at);
    }

    public function test_valid_receipt_detail_snapshots_are_preserved(): void
    {
        $detailId = $this->createReceiptDetail($this->receiptId, $this->articleId, [
            'articulo_sku_snapshot' => 'pan_integral_01',
            'articulo_nombre_snapshot' => 'Pan integral',
            'unidad_codigo_snapshot' => 'unidad',
            'unidad_simbolo_snapshot' => 'Un',
        ]);
        $detail = DB::table('detalle_recepciones_mercaderia')->where('id', $detailId)->first();

        $this->assertSame('pan_integral_01', $detail->articulo_sku_snapshot);
        $this->assertSame('Pan integral', $detail->articulo_nombre_snapshot);
        $this->assertSame('unidad', $detail->unidad_codigo_snapshot);
        $this->assertSame('Un', $detail->unidad_simbolo_snapshot);
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_receipt_detail_snapshot_is_rejected(string $column, string $value): void
    {
        $this->expectException(QueryException::class);
        $this->createReceiptDetail($this->receiptId, $this->articleId, [$column => $value]);
    }

    public static function invalidSnapshots(): array
    {
        return [
            'sku empty' => ['articulo_sku_snapshot', ''],
            'sku spaces' => ['articulo_sku_snapshot', '   '],
            'sku uppercase' => ['articulo_sku_snapshot', 'PAN_PRUEBA'],
            'sku invalid format' => ['articulo_sku_snapshot', 'pan.prueba'],
            'sku padded' => ['articulo_sku_snapshot', ' pan_prueba'],
            'name empty' => ['articulo_nombre_snapshot', ''],
            'name spaces' => ['articulo_nombre_snapshot', '   '],
            'name padded' => ['articulo_nombre_snapshot', 'Pan de prueba '],
            'unit code empty' => ['unidad_codigo_snapshot', ''],
            'unit code uppercase' => ['unidad_codigo_snapshot', 'KG'],
            'unit code invalid format' => ['unidad_codigo_snapshot', '1kg'],
            'unit code padded' => ['unidad_codigo_snapshot', ' kg'],
            'unit symbol empty' => ['unidad_simbolo_snapshot', ''],
            'unit symbol spaces' => ['unidad_simbolo_snapshot', '   '],
            'unit symbol padded' => ['unidad_simbolo_snapshot', 'kg '],
        ];
    }

    #[DataProvider('validQuantities')]
    public function test_valid_receipt_detail_quantity_is_accepted(string $quantity): void
    {
        $detailId = $this->createReceiptDetail(
            $this->receiptId,
            $this->articleId,
            ['cantidad' => $quantity],
        );

        $this->assertSame(
            $quantity,
            DB::table('detalle_recepciones_mercaderia')->where('id', $detailId)->value('cantidad'),
        );
    }

    public static function validQuantities(): array
    {
        return [
            'integer' => ['2.000'],
            'fractional' => ['0.125'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_receipt_detail_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createReceiptDetail(
            $this->receiptId,
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

    #[DataProvider('validCostStructures')]
    public function test_valid_receipt_detail_cost_structure_is_accepted(
        ?string $cost,
        ?string $reason,
    ): void {
        $detailId = $this->createReceiptDetail($this->receiptId, $this->articleId, [
            'costo_unitario' => $cost,
            'motivo_costo_cero' => $reason,
        ]);
        $detail = DB::table('detalle_recepciones_mercaderia')->where('id', $detailId)->first();

        $this->assertSame($cost, $detail->costo_unitario);
        $this->assertSame($reason, $detail->motivo_costo_cero);
    }

    public static function validCostStructures(): array
    {
        return [
            'unknown cost' => [null, null],
            'zero with reason' => ['0.00', 'Muestra sin costo'],
            'positive cost' => ['1250.50', null],
        ];
    }

    #[DataProvider('invalidCostStructures')]
    public function test_invalid_receipt_detail_cost_structure_is_rejected(
        ?string $cost,
        ?string $reason,
    ): void {
        $this->expectException(QueryException::class);
        $this->createReceiptDetail($this->receiptId, $this->articleId, [
            'costo_unitario' => $cost,
            'motivo_costo_cero' => $reason,
        ]);
    }

    public static function invalidCostStructures(): array
    {
        return [
            'null cost with reason' => [null, 'Motivo innecesario'],
            'zero without reason' => ['0.00', null],
            'zero with empty reason' => ['0.00', ''],
            'zero with spaces' => ['0.00', '   '],
            'zero with padded reason' => ['0.00', ' Motivo '],
            'positive with reason' => ['1.00', 'Motivo innecesario'],
            'negative' => ['-0.01', null],
            'NaN' => ['NaN', null],
        ];
    }

    public function test_duplicate_article_in_same_receipt_is_rejected(): void
    {
        $this->createReceiptDetail($this->receiptId, $this->articleId);

        $this->expectException(QueryException::class);
        $this->createReceiptDetail($this->receiptId, $this->articleId);
    }

    public function test_same_article_in_distinct_receipts_is_accepted(): void
    {
        $otherReceiptId = $this->createReceipt($this->userId, self::UUID_TWO);
        $this->createReceiptDetail($this->receiptId, $this->articleId);
        $this->createReceiptDetail($otherReceiptId, $this->articleId);

        $this->assertSame(
            2,
            DB::table('detalle_recepciones_mercaderia')->where('articulo_id', $this->articleId)->count(),
        );
    }

    public function test_distinct_articles_in_same_receipt_are_accepted(): void
    {
        $otherArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_distinto',
            'nombre' => 'Pan distinto',
        ]);
        $this->createReceiptDetail($this->receiptId, $this->articleId);
        $this->createReceiptDetail($this->receiptId, $otherArticleId, [
            'articulo_sku_snapshot' => 'pan_distinto',
            'articulo_nombre_snapshot' => 'Pan distinto',
        ]);

        $this->assertSame(
            2,
            DB::table('detalle_recepciones_mercaderia')->where('recepcion_mercaderia_id', $this->receiptId)->count(),
        );
    }
}
