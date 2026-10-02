<?php

namespace Tests\Feature\Database\BlockTen;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class InventoryCountDetailConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const COUNTED_AT = '2026-09-20 10:00:00-03';

    private int $articleId;

    private int $categoryId;

    private int $inventoryCountId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = $this->createRole();
        $this->userId = $this->createUser($roleId, ['nombre_usuario' => 'detalle_conteo']);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->inventoryCountId = $this->createInventoryCount($this->userId, $this->userId);
    }

    public function test_uncounted_inventory_count_detail_can_be_created_with_defaults(): void
    {
        $detailId = $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId);
        $detail = DB::table('detalle_conteos_inventario')->where('id', $detailId)->first();

        $this->assertNotNull($detail);
        $this->assertSame($this->inventoryCountId, $detail->conteo_inventario_id);
        $this->assertSame($this->articleId, $detail->articulo_id);
        $this->assertSame('pan_prueba', $detail->articulo_sku_snapshot);
        $this->assertSame('Pan de prueba', $detail->articulo_nombre_snapshot);
        $this->assertSame('kg', $detail->unidad_codigo_snapshot);
        $this->assertSame('kg', $detail->unidad_simbolo_snapshot);
        $this->assertNull($detail->cantidad_contada);
        $this->assertNull($detail->contada_en);
        $this->assertNull($detail->stock_teorico_snapshot);
        $this->assertNotNull($detail->created_at);
        $this->assertNotNull($detail->updated_at);
    }

    #[DataProvider('validCountedQuantities')]
    public function test_counted_inventory_count_detail_accepts_non_negative_quantity(string $quantity): void
    {
        $detailId = $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'cantidad_contada' => $quantity,
            'contada_en' => self::COUNTED_AT,
        ]);

        $this->assertSame(
            $quantity,
            DB::table('detalle_conteos_inventario')->where('id', $detailId)->value('cantidad_contada'),
        );
    }

    public static function validCountedQuantities(): array
    {
        return [
            'zero' => ['0.000'],
            'positive integer' => ['2.000'],
            'fractional' => ['0.125'],
        ];
    }

    #[DataProvider('invalidCountCapturePairs')]
    public function test_inventory_count_detail_rejects_incomplete_count_capture(
        ?string $quantity,
        ?string $countedAt,
    ): void {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'cantidad_contada' => $quantity,
            'contada_en' => $countedAt,
        ]);
    }

    public static function invalidCountCapturePairs(): array
    {
        return [
            'quantity without timestamp' => ['1.000', null],
            'timestamp without quantity' => [null, self::COUNTED_AT],
        ];
    }

    #[DataProvider('invalidCountedQuantities')]
    public function test_invalid_counted_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'cantidad_contada' => $quantity,
            'contada_en' => self::COUNTED_AT,
        ]);
    }

    public static function invalidCountedQuantities(): array
    {
        return [
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validTheoreticalStockSnapshots')]
    public function test_valid_theoretical_stock_snapshot_is_accepted(
        ?string $stock,
        ?string $storedStock,
    ): void {
        $detailId = $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'cantidad_contada' => '1.000',
            'contada_en' => self::COUNTED_AT,
            'stock_teorico_snapshot' => $stock,
        ]);

        $this->assertSame(
            $storedStock,
            DB::table('detalle_conteos_inventario')->where('id', $detailId)->value('stock_teorico_snapshot'),
        );
    }

    public static function validTheoreticalStockSnapshots(): array
    {
        return [
            'null' => [null, null],
            'zero' => ['0.000', '0.000'],
            'positive integer' => ['12.000', '12.000'],
            'fractional' => ['12.500', '12.500'],
        ];
    }

    #[DataProvider('invalidTheoreticalStockSnapshots')]
    public function test_invalid_theoretical_stock_snapshot_is_rejected(string $stock): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'cantidad_contada' => '1.000',
            'contada_en' => self::COUNTED_AT,
            'stock_teorico_snapshot' => $stock,
        ]);
    }

    public static function invalidTheoreticalStockSnapshots(): array
    {
        return [
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_theoretical_stock_snapshot_without_counted_quantity_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'stock_teorico_snapshot' => '1.000',
        ]);
    }

    public function test_valid_inventory_count_detail_snapshots_are_preserved(): void
    {
        $detailId = $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId, [
            'articulo_sku_snapshot' => 'pan_integral_01',
            'articulo_nombre_snapshot' => 'Pan integral',
            'unidad_codigo_snapshot' => 'unidad',
            'unidad_simbolo_snapshot' => 'Un',
        ]);
        $detail = DB::table('detalle_conteos_inventario')->where('id', $detailId)->first();

        $this->assertSame('pan_integral_01', $detail->articulo_sku_snapshot);
        $this->assertSame('Pan integral', $detail->articulo_nombre_snapshot);
        $this->assertSame('unidad', $detail->unidad_codigo_snapshot);
        $this->assertSame('Un', $detail->unidad_simbolo_snapshot);
    }

    #[DataProvider('invalidInventoryCountDetailSnapshots')]
    public function test_invalid_inventory_count_detail_snapshot_is_rejected(string $column, string $value): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail(
            $this->inventoryCountId,
            $this->articleId,
            [$column => $value],
        );
    }

    public static function invalidInventoryCountDetailSnapshots(): array
    {
        return [
            'sku empty' => ['articulo_sku_snapshot', ''],
            'sku leading space' => ['articulo_sku_snapshot', ' pan_prueba'],
            'sku trailing space' => ['articulo_sku_snapshot', 'pan_prueba '],
            'sku uppercase' => ['articulo_sku_snapshot', 'PAN_PRUEBA'],
            'sku invalid format' => ['articulo_sku_snapshot', 'pan.prueba'],
            'name empty' => ['articulo_nombre_snapshot', ''],
            'name spaces only' => ['articulo_nombre_snapshot', '   '],
            'name leading space' => ['articulo_nombre_snapshot', ' Pan de prueba'],
            'name trailing space' => ['articulo_nombre_snapshot', 'Pan de prueba '],
            'unit code empty' => ['unidad_codigo_snapshot', ''],
            'unit code leading space' => ['unidad_codigo_snapshot', ' kg'],
            'unit code trailing space' => ['unidad_codigo_snapshot', 'kg '],
            'unit code uppercase' => ['unidad_codigo_snapshot', 'KG'],
            'unit code invalid format' => ['unidad_codigo_snapshot', '1kg'],
            'unit symbol empty' => ['unidad_simbolo_snapshot', ''],
            'unit symbol spaces only' => ['unidad_simbolo_snapshot', '   '],
            'unit symbol leading space' => ['unidad_simbolo_snapshot', ' Kg'],
            'unit symbol trailing space' => ['unidad_simbolo_snapshot', 'Kg '],
        ];
    }

    public function test_same_article_twice_in_one_inventory_count_is_rejected(): void
    {
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId);

        $this->expectException(QueryException::class);
        $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId);
    }

    public function test_same_article_in_distinct_inventory_counts_is_accepted(): void
    {
        $otherCountId = $this->createInventoryCount($this->userId, $this->userId, [
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => '2026-09-20 10:00:00-03',
            'created_at' => '2026-09-20 09:00:00-03',
        ]);
        $firstDetailId = $this->createInventoryCountDetail($this->inventoryCountId, $this->articleId);
        $secondDetailId = $this->createInventoryCountDetail($otherCountId, $this->articleId);

        $this->assertNotSame($firstDetailId, $secondDetailId);
        $this->assertSame(
            2,
            DB::table('detalle_conteos_inventario')->where('articulo_id', $this->articleId)->count(),
        );
    }

    public function test_inactive_article_is_structurally_allowed_in_inventory_count_detail(): void
    {
        $inactiveArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_inactivo',
            'nombre' => 'Pan inactivo',
            'activo' => false,
        ]);
        $detailId = $this->createInventoryCountDetail($this->inventoryCountId, $inactiveArticleId, [
            'articulo_sku_snapshot' => 'pan_inactivo',
            'articulo_nombre_snapshot' => 'Pan inactivo',
        ]);

        $this->assertFalse((bool) DB::table('articulos')->where('id', $inactiveArticleId)->value('activo'));
        $this->assertSame(
            $inactiveArticleId,
            DB::table('detalle_conteos_inventario')->where('id', $detailId)->value('articulo_id'),
        );
    }
}
