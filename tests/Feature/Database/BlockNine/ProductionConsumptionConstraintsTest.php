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

class ProductionConsumptionConstraintsTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $categoryId;

    private int $componentId;

    private int $productionId;

    private int $recipeDetailId;

    private int $recipeId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = $this->createRole();
        $this->userId = $this->createUser($roleId, ['nombre_usuario' => 'consumo_produccion']);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $productId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_consumo',
            'nombre' => 'Pan consumo',
        ]);
        $this->componentId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'harina_prueba',
            'nombre' => 'Harina de prueba',
            'tipo_articulo' => 'materia_prima',
        ]);
        $this->recipeId = $this->createRecipe($productId, ['activa' => true]);
        $this->recipeDetailId = $this->createRecipeDetail($this->recipeId, $this->componentId);
        $this->productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
    }

    public function test_planned_consumption_can_be_created_with_minimum_data_and_defaults(): void
    {
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        );
        $consumption = DB::table('consumos_produccion')->where('id', $consumptionId)->first();

        $this->assertNotNull($consumption);
        $this->assertSame($this->productionId, $consumption->produccion_id);
        $this->assertSame($this->recipeDetailId, $consumption->detalle_receta_id);
        $this->assertSame($this->componentId, $consumption->articulo_componente_id);
        $this->assertSame('harina_prueba', $consumption->articulo_sku_snapshot);
        $this->assertSame('Harina de prueba', $consumption->articulo_nombre_snapshot);
        $this->assertSame('kg', $consumption->unidad_codigo_snapshot);
        $this->assertSame('kg', $consumption->unidad_simbolo_snapshot);
        $this->assertSame('1.000', $consumption->cantidad_teorica);
        $this->assertNull($consumption->cantidad_real);
        $this->assertNotNull($consumption->created_at);
        $this->assertNotNull($consumption->updated_at);
    }

    public function test_extra_consumption_with_null_origin_and_theory_is_accepted(): void
    {
        $consumptionId = $this->createProductionConsumption($this->productionId, $this->componentId);
        $consumption = DB::table('consumos_produccion')->where('id', $consumptionId)->first();

        $this->assertNull($consumption->detalle_receta_id);
        $this->assertNull($consumption->cantidad_teorica);
    }

    #[DataProvider('invalidProductionConsumptionSkuSnapshots')]
    public function test_invalid_production_consumption_sku_snapshot_is_rejected(string $sku): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['articulo_sku_snapshot' => $sku],
        );
    }

    public static function invalidProductionConsumptionSkuSnapshots(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' harina_prueba'],
            'trailing space' => ['harina_prueba '],
            'uppercase' => ['HARINA_PRUEBA'],
            'invalid format' => ['harina.prueba'],
        ];
    }

    #[DataProvider('invalidProductionConsumptionNameSnapshots')]
    public function test_invalid_production_consumption_name_snapshot_is_rejected(string $name): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['articulo_nombre_snapshot' => $name],
        );
    }

    public static function invalidProductionConsumptionNameSnapshots(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Harina de prueba'],
            'trailing space' => ['Harina de prueba '],
        ];
    }

    #[DataProvider('invalidProductionConsumptionUnitCodeSnapshots')]
    public function test_invalid_production_consumption_unit_code_snapshot_is_rejected(string $code): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['unidad_codigo_snapshot' => $code],
        );
    }

    public static function invalidProductionConsumptionUnitCodeSnapshots(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' kg'],
            'trailing space' => ['kg '],
            'uppercase' => ['KG'],
            'invalid format' => ['1kg'],
        ];
    }

    #[DataProvider('invalidProductionConsumptionUnitSymbolSnapshots')]
    public function test_invalid_production_consumption_unit_symbol_snapshot_is_rejected(string $symbol): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['unidad_simbolo_snapshot' => $symbol],
        );
    }

    public static function invalidProductionConsumptionUnitSymbolSnapshots(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Kg'],
            'trailing space' => ['Kg '],
        ];
    }

    public function test_production_consumption_unit_symbol_preserves_visible_capitalization(): void
    {
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['unidad_simbolo_snapshot' => 'Kg'],
        );

        $this->assertSame(
            'Kg',
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('unidad_simbolo_snapshot'),
        );
    }

    #[DataProvider('validPlannedTheoreticalQuantities')]
    public function test_valid_planned_theoretical_quantity_is_accepted(string $quantity): void
    {
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            $quantity,
        );

        $this->assertSame(
            $quantity,
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('cantidad_teorica'),
        );
    }

    public static function validPlannedTheoreticalQuantities(): array
    {
        return [
            'positive integer' => ['2.000'],
            'fractional' => ['0.125'],
        ];
    }

    #[DataProvider('invalidPlannedTheoreticalQuantities')]
    public function test_invalid_planned_theoretical_quantity_is_rejected(?string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            $quantity,
        );
    }

    public static function invalidPlannedTheoreticalQuantities(): array
    {
        return [
            'null' => [null],
            'zero' => ['0.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('invalidExtraTheoreticalQuantities')]
    public function test_extra_consumption_rejects_informed_theoretical_quantity(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            null,
            $quantity,
        );
    }

    public static function invalidExtraTheoreticalQuantities(): array
    {
        return [
            'zero' => ['0.000'],
            'positive' => ['1.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validProductionConsumptionActualQuantities')]
    public function test_valid_production_consumption_actual_quantity_is_accepted(
        ?string $quantity,
        ?string $storedQuantity,
    ): void {
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['cantidad_real' => $quantity],
        );

        $this->assertSame(
            $storedQuantity,
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('cantidad_real'),
        );
    }

    public static function validProductionConsumptionActualQuantities(): array
    {
        return [
            'null' => [null, null],
            'zero' => ['0.000', '0.000'],
            'positive integer' => ['2.000', '2.000'],
            'fractional' => ['0.125', '0.125'],
        ];
    }

    #[DataProvider('invalidProductionConsumptionActualQuantities')]
    public function test_invalid_production_consumption_actual_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
            ['cantidad_real' => $quantity],
        );
    }

    public static function invalidProductionConsumptionActualQuantities(): array
    {
        return [
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_duplicate_planned_consumption_for_same_production_is_rejected(): void
    {
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        );

        $this->expectException(QueryException::class);
        $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '2.000',
        );
    }

    public function test_same_recipe_detail_can_be_consumed_by_distinct_productions(): void
    {
        $otherProductionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
        $firstId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        );
        $secondId = $this->createProductionConsumption(
            $otherProductionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        );

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('consumos_produccion')->where('detalle_receta_id', $this->recipeDetailId)->count());
    }

    public function test_duplicate_extra_component_for_same_production_is_rejected(): void
    {
        $this->createProductionConsumption($this->productionId, $this->componentId);

        $this->expectException(QueryException::class);
        $this->createProductionConsumption($this->productionId, $this->componentId);
    }

    public function test_same_extra_component_can_be_consumed_by_distinct_productions(): void
    {
        $otherProductionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
        $firstId = $this->createProductionConsumption($this->productionId, $this->componentId);
        $secondId = $this->createProductionConsumption($otherProductionId, $this->componentId);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('consumos_produccion')->whereNull('detalle_receta_id')->count());
    }

    public function test_planned_and_extra_consumption_can_share_component_in_same_production(): void
    {
        $plannedId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '1.000',
        );
        $extraId = $this->createProductionConsumption($this->productionId, $this->componentId);

        $this->assertNotSame($plannedId, $extraId);
        $this->assertSame(
            2,
            DB::table('consumos_produccion')
                ->where('produccion_id', $this->productionId)
                ->where('articulo_componente_id', $this->componentId)
                ->count(),
        );
    }

    public function test_recipe_detail_from_another_recipe_is_structurally_allowed(): void
    {
        $otherProductId = $this->createDistinctArticle('pan_otra_receta', 'Pan otra receta');
        $otherRecipeId = $this->createRecipe($otherProductId, ['activa' => true]);
        $otherRecipeDetailId = $this->createRecipeDetail($otherRecipeId, $this->componentId);
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $otherRecipeDetailId,
            '1.000',
        );

        $this->assertNotSame($this->recipeId, $otherRecipeId);
        $this->assertSame(
            $otherRecipeDetailId,
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('detalle_receta_id'),
        );
    }

    public function test_component_different_from_recipe_detail_is_structurally_allowed(): void
    {
        $otherComponentId = $this->createDistinctArticle(
            'azucar_distinta',
            'Azucar distinta',
            ['tipo_articulo' => 'materia_prima'],
        );
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $otherComponentId,
            $this->recipeDetailId,
            '1.000',
            [
                'articulo_sku_snapshot' => 'azucar_distinta',
                'articulo_nombre_snapshot' => 'Azucar distinta',
            ],
        );

        $this->assertNotSame($this->componentId, $otherComponentId);
        $this->assertSame(
            $otherComponentId,
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('articulo_componente_id'),
        );
    }

    public function test_inactive_component_is_structurally_allowed(): void
    {
        $inactiveComponentId = $this->createDistinctArticle(
            'harina_inactiva',
            'Harina inactiva',
            ['tipo_articulo' => 'materia_prima', 'activo' => false],
        );
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $inactiveComponentId,
            null,
            null,
            [
                'articulo_sku_snapshot' => 'harina_inactiva',
                'articulo_nombre_snapshot' => 'Harina inactiva',
            ],
        );

        $this->assertFalse((bool) DB::table('articulos')->where('id', $inactiveComponentId)->value('activo'));
        $this->assertSame(
            $inactiveComponentId,
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('articulo_componente_id'),
        );
    }

    public function test_non_raw_material_component_is_structurally_allowed(): void
    {
        $resaleComponentId = $this->createDistinctArticle(
            'insumo_reventa',
            'Insumo reventa',
            ['tipo_articulo' => 'reventa'],
        );
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $resaleComponentId,
            null,
            null,
            [
                'articulo_sku_snapshot' => 'insumo_reventa',
                'articulo_nombre_snapshot' => 'Insumo reventa',
            ],
        );

        $this->assertSame('reventa', DB::table('articulos')->where('id', $resaleComponentId)->value('tipo_articulo'));
        $this->assertSame(
            $resaleComponentId,
            DB::table('consumos_produccion')->where('id', $consumptionId)->value('articulo_componente_id'),
        );
    }

    public function test_actual_consumption_can_differ_from_theoretical_consumption(): void
    {
        $consumptionId = $this->createProductionConsumption(
            $this->productionId,
            $this->componentId,
            $this->recipeDetailId,
            '10.000',
            ['cantidad_real' => '7.500'],
        );
        $consumption = DB::table('consumos_produccion')->where('id', $consumptionId)->first();

        $this->assertSame('10.000', $consumption->cantidad_teorica);
        $this->assertSame('7.500', $consumption->cantidad_real);
    }

    private function createDistinctArticle(string $sku, string $name, array $overrides = []): int
    {
        return $this->createArticle(
            $this->categoryId,
            $this->unitId,
            array_merge(['sku' => $sku, 'nombre' => $name], $overrides),
        );
    }
}
