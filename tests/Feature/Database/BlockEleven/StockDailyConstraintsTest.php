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

class StockDailyConstraintsTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $otherArticleId;

    private int $movementId;

    protected function setUp(): void
    {
        parent::setUp();

        $userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'stock_diario'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($categoryId, $unitId);
        $this->otherArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'pan_stock_dos',
            'nombre' => 'Pan stock dos',
        ]);
        $this->movementId = $this->createInventoryMovement(
            $this->articleId,
            $userId,
            'ajuste_manual_entrada',
            '85000000-0000-4000-8000-000000000001',
        );
    }

    public function test_valid_daily_stock_uses_calculation_timestamp_default(): void
    {
        $stockId = $this->createStockDaily($this->articleId, [
            'ultimo_movimiento_id' => $this->movementId,
        ]);
        $stock = DB::table('stock_diario')->where('id', $stockId)->first();

        $this->assertNotNull($stock);
        $this->assertSame('0.000', $stock->stock_apertura);
        $this->assertSame('1.000', $stock->total_entradas);
        $this->assertSame('0.000', $stock->total_salidas);
        $this->assertSame('1.000', $stock->stock_cierre);
        $this->assertNotNull($stock->calculado_en);
        $this->assertSame($this->movementId, $stock->ultimo_movimiento_id);
    }

    #[DataProvider('negativeStockValues')]
    public function test_negative_daily_stock_value_is_rejected(array $overrides): void
    {
        $this->expectException(QueryException::class);
        $this->createStockDaily($this->articleId, $overrides);
    }

    public static function negativeStockValues(): array
    {
        return [
            'opening' => [[
                'stock_apertura' => '-1.000',
                'total_entradas' => '1.000',
                'total_salidas' => '0.000',
                'stock_cierre' => '0.000',
            ]],
            'entries' => [[
                'stock_apertura' => '1.000',
                'total_entradas' => '-1.000',
                'total_salidas' => '0.000',
                'stock_cierre' => '0.000',
            ]],
            'outputs' => [[
                'stock_apertura' => '0.000',
                'total_entradas' => '0.000',
                'total_salidas' => '-1.000',
                'stock_cierre' => '1.000',
            ]],
            'closing' => [[
                'stock_apertura' => '0.000',
                'total_entradas' => '0.000',
                'total_salidas' => '1.000',
                'stock_cierre' => '-1.000',
            ]],
        ];
    }

    #[DataProvider('quantitativeColumns')]
    public function test_nan_daily_stock_value_is_rejected(string $column): void
    {
        $this->expectException(QueryException::class);
        $this->createStockDaily($this->articleId, [$column => 'NaN']);
    }

    public static function quantitativeColumns(): array
    {
        return [
            'opening' => ['stock_apertura'],
            'entries' => ['total_entradas'],
            'outputs' => ['total_salidas'],
            'closing' => ['stock_cierre'],
        ];
    }

    public function test_inconsistent_daily_stock_equation_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createStockDaily($this->articleId, ['stock_cierre' => '2.000']);
    }

    public function test_duplicate_article_and_commercial_date_are_rejected(): void
    {
        $this->createStockDaily($this->articleId);

        $this->expectException(QueryException::class);
        $this->createStockDaily($this->articleId);
    }

    public function test_same_article_on_distinct_commercial_dates_is_accepted(): void
    {
        $this->createStockDaily($this->articleId);
        $this->createStockDaily($this->articleId, ['fecha_comercial' => '2026-09-21']);

        $this->assertSame(
            2,
            DB::table('stock_diario')->where('articulo_id', $this->articleId)->count(),
        );
    }

    public function test_distinct_articles_on_same_commercial_date_are_accepted(): void
    {
        $this->createStockDaily($this->articleId);
        $this->createStockDaily($this->otherArticleId);

        $this->assertSame(
            2,
            DB::table('stock_diario')->where('fecha_comercial', '2026-09-20')->count(),
        );
    }

    public function test_null_last_movement_is_accepted(): void
    {
        $stockId = $this->createStockDaily($this->articleId);

        $this->assertNull(
            DB::table('stock_diario')->where('id', $stockId)->value('ultimo_movimiento_id'),
        );
    }

    public function test_missing_last_movement_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createStockDaily($this->articleId, [
            'ultimo_movimiento_id' => 9_999_999_999,
        ]);
    }
}
