<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class InventoryCountLedgerExtensionConstraintsTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const COUNTED_AT = '2026-09-20 10:00:00-03';

    private int $articleId;

    private int $inventoryCountId;

    private int $movementId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'conteo_ledger'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($categoryId, $unitId);
        $this->inventoryCountId = $this->createInventoryCount($this->userId, $this->userId);
        $this->movementId = $this->createInventoryMovement(
            $this->articleId,
            $this->userId,
            'ajuste_manual_entrada',
            '84000000-0000-4000-8000-000000000001',
        );
    }

    public function test_uncounted_line_allows_null_observed_movement(): void
    {
        $detailId = $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $this->articleId,
        );

        $this->assertNull(
            DB::table('detalle_conteos_inventario')
                ->where('id', $detailId)
                ->value('movimiento_inventario_observado_id'),
        );
    }

    public function test_counted_line_accepts_observed_movement(): void
    {
        $detailId = $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $this->articleId,
            [
                'cantidad_contada' => '1.000',
                'contada_en' => self::COUNTED_AT,
                'movimiento_inventario_observado_id' => $this->movementId,
            ],
        );

        $this->assertSame(
            $this->movementId,
            DB::table('detalle_conteos_inventario')
                ->where('id', $detailId)
                ->value('movimiento_inventario_observado_id'),
        );
    }

    public function test_counted_line_allows_null_observed_movement(): void
    {
        $detailId = $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $this->articleId,
            [
                'cantidad_contada' => '1.000',
                'contada_en' => self::COUNTED_AT,
            ],
        );

        $this->assertNull(
            DB::table('detalle_conteos_inventario')
                ->where('id', $detailId)
                ->value('movimiento_inventario_observado_id'),
        );
    }

    public function test_observed_movement_without_count_capture_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $this->articleId,
            ['movimiento_inventario_observado_id' => $this->movementId],
        );
    }

    public function test_missing_observed_movement_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $this->articleId,
            [
                'cantidad_contada' => '1.000',
                'contada_en' => self::COUNTED_AT,
                'movimiento_inventario_observado_id' => 9_999_999_999,
            ],
        );
    }
}
