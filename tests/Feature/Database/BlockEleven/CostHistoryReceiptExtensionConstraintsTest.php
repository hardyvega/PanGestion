<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class CostHistoryReceiptExtensionConstraintsTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $receiptDetailId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'costo_recepcion'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($categoryId, $unitId);
        $receiptId = $this->createReceipt(
            $this->userId,
            '86000000-0000-4000-8000-000000000001',
        );
        $this->receiptDetailId = $this->createReceiptDetail(
            $receiptId,
            $this->articleId,
        );
    }

    public function test_cost_history_without_receipt_detail_remains_allowed(): void
    {
        $historyId = $this->createArticleCostHistory($this->articleId, $this->userId);

        $this->assertNull(
            DB::table('historial_costos_articulo')
                ->where('id', $historyId)
                ->value('detalle_recepcion_mercaderia_id'),
        );
    }

    public function test_cost_history_accepts_valid_receipt_detail(): void
    {
        $historyId = $this->createArticleCostHistory(
            $this->articleId,
            $this->userId,
            ['detalle_recepcion_mercaderia_id' => $this->receiptDetailId],
        );

        $this->assertSame(
            $this->receiptDetailId,
            DB::table('historial_costos_articulo')
                ->where('id', $historyId)
                ->value('detalle_recepcion_mercaderia_id'),
        );
    }

    public function test_receipt_detail_can_only_be_linked_to_one_cost_history_record(): void
    {
        $this->createArticleCostHistory(
            $this->articleId,
            $this->userId,
            ['detalle_recepcion_mercaderia_id' => $this->receiptDetailId],
        );

        $this->expectException(QueryException::class);
        $this->createArticleCostHistory(
            $this->articleId,
            $this->userId,
            ['detalle_recepcion_mercaderia_id' => $this->receiptDetailId],
        );
    }

    public function test_multiple_null_receipt_detail_links_are_allowed(): void
    {
        $this->createArticleCostHistory($this->articleId, $this->userId);
        $this->createArticleCostHistory($this->articleId, $this->userId, [
            'costo_anterior' => '1000.00',
            'costo_nuevo' => '1100.00',
        ]);

        $this->assertSame(
            2,
            DB::table('historial_costos_articulo')
                ->whereNull('detalle_recepcion_mercaderia_id')
                ->count(),
        );
    }

    public function test_missing_receipt_detail_link_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createArticleCostHistory(
            $this->articleId,
            $this->userId,
            ['detalle_recepcion_mercaderia_id' => 9_999_999_999],
        );
    }
}
