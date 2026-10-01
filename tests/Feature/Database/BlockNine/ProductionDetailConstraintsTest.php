<?php

namespace Tests\Feature\Database\BlockNine;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ProductionDetailConstraintsTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $categoryId;

    private int $productionId;

    private int $recipeId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = $this->createRole();
        $this->userId = $this->createUser($roleId, ['nombre_usuario' => 'detalle_produccion']);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->recipeId = $this->createRecipe($this->articleId, ['activa' => true]);
        $this->productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
    }

    public function test_production_detail_can_be_created_with_minimum_data_and_defaults(): void
    {
        $detailId = $this->createProductionDetail($this->productionId, $this->articleId);
        $detail = DB::table('detalle_producciones')->where('id', $detailId)->first();

        $this->assertNotNull($detail);
        $this->assertSame($this->productionId, $detail->produccion_id);
        $this->assertSame($this->articleId, $detail->articulo_id);
        $this->assertSame('pan_prueba', $detail->articulo_sku_snapshot);
        $this->assertSame('Pan de prueba', $detail->articulo_nombre_snapshot);
        $this->assertSame('kg', $detail->unidad_codigo_snapshot);
        $this->assertSame('kg', $detail->unidad_simbolo_snapshot);
        $this->assertSame('1.000', $detail->cantidad_teorica);
        $this->assertNull($detail->cantidad_real);
        $this->assertNotNull($detail->created_at);
        $this->assertNotNull($detail->updated_at);
    }

    #[DataProvider('invalidProductionDetailSkuSnapshots')]
    public function test_invalid_production_detail_sku_snapshot_is_rejected(string $sku): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['articulo_sku_snapshot' => $sku],
        );
    }

    public static function invalidProductionDetailSkuSnapshots(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' pan_prueba'],
            'trailing space' => ['pan_prueba '],
            'uppercase' => ['PAN_PRUEBA'],
            'invalid format' => ['pan.prueba'],
        ];
    }

    #[DataProvider('invalidProductionDetailNameSnapshots')]
    public function test_invalid_production_detail_name_snapshot_is_rejected(string $name): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['articulo_nombre_snapshot' => $name],
        );
    }

    public static function invalidProductionDetailNameSnapshots(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Pan de prueba'],
            'trailing space' => ['Pan de prueba '],
        ];
    }

    #[DataProvider('invalidProductionDetailUnitCodeSnapshots')]
    public function test_invalid_production_detail_unit_code_snapshot_is_rejected(string $code): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['unidad_codigo_snapshot' => $code],
        );
    }

    public static function invalidProductionDetailUnitCodeSnapshots(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' kg'],
            'trailing space' => ['kg '],
            'uppercase' => ['KG'],
            'invalid format' => ['1kg'],
        ];
    }

    #[DataProvider('invalidProductionDetailUnitSymbolSnapshots')]
    public function test_invalid_production_detail_unit_symbol_snapshot_is_rejected(string $symbol): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['unidad_simbolo_snapshot' => $symbol],
        );
    }

    public static function invalidProductionDetailUnitSymbolSnapshots(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Kg'],
            'trailing space' => ['Kg '],
        ];
    }

    public function test_production_detail_unit_symbol_preserves_visible_capitalization(): void
    {
        $detailId = $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['unidad_simbolo_snapshot' => 'Kg'],
        );

        $this->assertSame(
            'Kg',
            DB::table('detalle_producciones')->where('id', $detailId)->value('unidad_simbolo_snapshot'),
        );
    }

    #[DataProvider('validProductionDetailTheoreticalQuantities')]
    public function test_valid_production_detail_theoretical_quantity_is_accepted(string $quantity): void
    {
        $detailId = $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['cantidad_teorica' => $quantity],
        );

        $this->assertSame(
            $quantity,
            DB::table('detalle_producciones')->where('id', $detailId)->value('cantidad_teorica'),
        );
    }

    public static function validProductionDetailTheoreticalQuantities(): array
    {
        return [
            'positive integer' => ['2.000'],
            'fractional' => ['0.125'],
        ];
    }

    #[DataProvider('invalidProductionDetailTheoreticalQuantities')]
    public function test_invalid_production_detail_theoretical_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['cantidad_teorica' => $quantity],
        );
    }

    public static function invalidProductionDetailTheoreticalQuantities(): array
    {
        return [
            'zero' => ['0.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validProductionDetailActualQuantities')]
    public function test_valid_production_detail_actual_quantity_is_accepted(
        ?string $quantity,
        ?string $storedQuantity,
    ): void {
        $detailId = $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['cantidad_real' => $quantity],
        );

        $this->assertSame(
            $storedQuantity,
            DB::table('detalle_producciones')->where('id', $detailId)->value('cantidad_real'),
        );
    }

    public static function validProductionDetailActualQuantities(): array
    {
        return [
            'null' => [null, null],
            'zero' => ['0.000', '0.000'],
            'positive integer' => ['2.000', '2.000'],
            'fractional' => ['0.125', '0.125'],
        ];
    }

    #[DataProvider('invalidProductionDetailActualQuantities')]
    public function test_invalid_production_detail_actual_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionDetail(
            $this->productionId,
            $this->articleId,
            ['cantidad_real' => $quantity],
        );
    }

    public static function invalidProductionDetailActualQuantities(): array
    {
        return [
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_second_detail_for_same_production_is_rejected(): void
    {
        $this->createProductionDetail($this->productionId, $this->articleId);

        $this->expectException(QueryException::class);
        $this->createProductionDetail($this->productionId, $this->articleId);
    }

    public function test_same_recipe_can_produce_details_in_distinct_productions(): void
    {
        $otherProductionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
        $firstDetailId = $this->createProductionDetail($this->productionId, $this->articleId);
        $secondDetailId = $this->createProductionDetail($otherProductionId, $this->articleId);

        $this->assertNotSame($firstDetailId, $secondDetailId);
        $this->assertSame(2, DB::table('detalle_producciones')->where('articulo_id', $this->articleId)->count());
    }

    public function test_article_different_from_recipe_target_is_structurally_allowed(): void
    {
        $otherArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'producto_distinto',
            'nombre' => 'Producto distinto',
        ]);
        $detailId = $this->createProductionDetail($this->productionId, $otherArticleId);

        $this->assertNotSame($this->articleId, $otherArticleId);
        $this->assertSame($otherArticleId, DB::table('detalle_producciones')->where('id', $detailId)->value('articulo_id'));
    }

    public function test_inactive_article_is_structurally_allowed_in_production_detail(): void
    {
        $inactiveArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'producto_inactivo',
            'nombre' => 'Producto inactivo',
            'activo' => false,
        ]);
        $detailId = $this->createProductionDetail($this->productionId, $inactiveArticleId);

        $this->assertFalse((bool) DB::table('articulos')->where('id', $inactiveArticleId)->value('activo'));
        $this->assertSame($inactiveArticleId, DB::table('detalle_producciones')->where('id', $detailId)->value('articulo_id'));
    }

    public function test_non_elaborated_article_is_structurally_allowed_in_production_detail(): void
    {
        $rawMaterialId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'producto_materia',
            'nombre' => 'Producto materia',
            'tipo_articulo' => 'materia_prima',
        ]);
        $detailId = $this->createProductionDetail($this->productionId, $rawMaterialId);

        $this->assertSame('materia_prima', DB::table('articulos')->where('id', $rawMaterialId)->value('tipo_articulo'));
        $this->assertSame($rawMaterialId, DB::table('detalle_producciones')->where('id', $detailId)->value('articulo_id'));
    }

    public function test_confirmed_production_can_have_null_actual_quantity(): void
    {
        $productionId = $this->createConfirmedProduction();
        $detailId = $this->createProductionDetail($productionId, $this->articleId);

        $this->assertNull(DB::table('detalle_producciones')->where('id', $detailId)->value('cantidad_real'));
    }

    public function test_confirmed_production_can_have_zero_actual_quantity(): void
    {
        $productionId = $this->createConfirmedProduction();
        $detailId = $this->createProductionDetail(
            $productionId,
            $this->articleId,
            ['cantidad_real' => '0.000'],
        );

        $this->assertSame('0.000', DB::table('detalle_producciones')->where('id', $detailId)->value('cantidad_real'));
    }

    public function test_actual_quantity_can_differ_from_theoretical_quantity(): void
    {
        $detailId = $this->createProductionDetail($this->productionId, $this->articleId, [
            'cantidad_teorica' => '10.000',
            'cantidad_real' => '7.500',
        ]);
        $detail = DB::table('detalle_producciones')->where('id', $detailId)->first();

        $this->assertSame('10.000', $detail->cantidad_teorica);
        $this->assertSame('7.500', $detail->cantidad_real);
    }

    private function createConfirmedProduction(): int
    {
        return $this->createProduction($this->recipeId, $this->userId, $this->userId, [
            'estado' => 'confirmada',
            'confirmada_en' => '2026-09-20 10:00:00-03',
            'usuario_confirmador_id' => $this->userId,
            'created_at' => '2026-09-20 09:00:00-03',
        ]);
    }
}
