<?php

namespace Tests\Feature\Database\BlockFive;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class RecipeDetailConstraintsTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $categoryId;

    private int $componentId;

    private int $recipeId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->recipeId = $this->createRecipe($this->articleId);
        $this->componentId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'harina_prueba',
            'nombre' => 'Harina de prueba',
            'tipo_articulo' => 'materia_prima',
        ]);
    }

    public function test_recipe_detail_can_be_created_with_minimum_data(): void
    {
        $detailId = $this->createRecipeDetail($this->recipeId, $this->componentId);
        $detail = DB::table('detalle_recetas')->where('id', $detailId)->first();

        $this->assertNotNull($detail);
        $this->assertSame($detailId, $detail->id);
        $this->assertSame($this->recipeId, $detail->receta_id);
        $this->assertSame($this->componentId, $detail->articulo_componente_id);
        $this->assertSame('1.000', $detail->cantidad);
        $this->assertNotNull($detail->created_at);
        $this->assertNotNull($detail->updated_at);
    }

    #[DataProvider('validQuantities')]
    public function test_valid_recipe_detail_quantity_is_accepted(string $quantity): void
    {
        $detailId = $this->createRecipeDetail(
            $this->recipeId,
            $this->componentId,
            ['cantidad' => $quantity],
        );

        $this->assertSame($quantity, DB::table('detalle_recetas')->where('id', $detailId)->value('cantidad'));
    }

    public static function validQuantities(): array
    {
        return [
            'positive decimal' => ['1.250'],
            'smallest approved scale value' => ['0.001'],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_recipe_detail_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createRecipeDetail(
            $this->recipeId,
            $this->componentId,
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

    public function test_duplicate_component_for_same_recipe_is_rejected(): void
    {
        $this->createRecipeDetail($this->recipeId, $this->componentId);

        $this->expectException(QueryException::class);
        $this->createRecipeDetail($this->recipeId, $this->componentId);
    }

    public function test_same_recipe_can_have_different_components(): void
    {
        $otherComponentId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'sal_prueba',
            'nombre' => 'Sal de prueba',
            'tipo_articulo' => 'materia_prima',
        ]);
        $this->createRecipeDetail($this->recipeId, $this->componentId);
        $this->createRecipeDetail($this->recipeId, $otherComponentId);

        $this->assertSame(2, DB::table('detalle_recetas')->where('receta_id', $this->recipeId)->count());
    }

    public function test_different_recipes_can_use_same_component(): void
    {
        $otherRecipeId = $this->createRecipe($this->articleId, ['version' => 2]);
        $this->createRecipeDetail($this->recipeId, $this->componentId);
        $this->createRecipeDetail($otherRecipeId, $this->componentId);

        $this->assertSame(
            2,
            DB::table('detalle_recetas')->where('articulo_componente_id', $this->componentId)->count(),
        );
    }

    public function test_recipe_detail_rejects_missing_recipe(): void
    {
        $missingRecipeId = $this->recipeId;
        $this->assertSame(1, DB::table('recetas')->where('id', $missingRecipeId)->delete());

        $this->expectException(QueryException::class);
        $this->createRecipeDetail($missingRecipeId, $this->componentId);
    }

    public function test_recipe_detail_rejects_missing_component(): void
    {
        $missingComponentId = $this->componentId;
        $this->assertSame(1, DB::table('articulos')->where('id', $missingComponentId)->delete());

        $this->expectException(QueryException::class);
        $this->createRecipeDetail($this->recipeId, $missingComponentId);
    }
}
