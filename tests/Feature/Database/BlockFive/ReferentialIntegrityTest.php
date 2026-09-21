<?php

namespace Tests\Feature\Database\BlockFive;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_article_with_recipe_cannot_be_deleted(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId);

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $articleId)->delete();
    }

    public function test_recipe_with_detail_cannot_be_deleted(): void
    {
        [$recipeId, $componentId] = $this->createRecipeAndComponentFixtures();
        $this->createRecipeDetail($recipeId, $componentId);

        $this->expectException(QueryException::class);
        DB::table('recetas')->where('id', $recipeId)->delete();
    }

    public function test_component_article_used_by_recipe_cannot_be_deleted(): void
    {
        [$recipeId, $componentId] = $this->createRecipeAndComponentFixtures();
        $this->createRecipeDetail($recipeId, $componentId);

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $componentId)->delete();
    }

    private function createArticleFixture(array $overrides = []): int
    {
        return $this->createArticle(
            $this->createArticleCategory(),
            $this->createUnitOfMeasure(),
            $overrides,
        );
    }

    private function createRecipeAndComponentFixtures(): array
    {
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $articleId = $this->createArticle($categoryId, $unitId);
        $recipeId = $this->createRecipe($articleId);
        $componentId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'harina_prueba',
            'nombre' => 'Harina de prueba',
            'tipo_articulo' => 'materia_prima',
        ]);

        return [$recipeId, $componentId];
    }
}
