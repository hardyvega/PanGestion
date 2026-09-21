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

class RecipeConstraintsTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $categoryId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
    }

    public function test_recipe_can_be_created_with_minimum_data_and_defaults(): void
    {
        $articleId = $this->createArticleFixture();
        $recipeId = $this->createRecipe($articleId);
        $recipe = DB::table('recetas')->where('id', $recipeId)->first();

        $this->assertNotNull($recipe);
        $this->assertSame($recipeId, $recipe->id);
        $this->assertSame($articleId, $recipe->articulo_id);
        $this->assertSame(1, $recipe->version);
        $this->assertSame('1.000', $recipe->rendimiento_cantidad);
        $this->assertFalse($recipe->activa);
        $this->assertNull($recipe->observacion);
        $this->assertNotNull($recipe->created_at);
        $this->assertNotNull($recipe->updated_at);
    }

    #[DataProvider('validVersions')]
    public function test_valid_recipe_version_is_accepted(int $version): void
    {
        $recipeId = $this->createRecipe($this->createArticleFixture(), ['version' => $version]);

        $this->assertSame($version, DB::table('recetas')->where('id', $recipeId)->value('version'));
    }

    public static function validVersions(): array
    {
        return [
            'first version' => [1],
            'later version' => [7],
        ];
    }

    #[DataProvider('invalidVersions')]
    public function test_invalid_recipe_version_is_rejected(int $version): void
    {
        $articleId = $this->createArticleFixture();

        $this->expectException(QueryException::class);
        $this->createRecipe($articleId, ['version' => $version]);
    }

    public static function invalidVersions(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_duplicate_recipe_version_for_same_article_is_rejected(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId);

        $this->expectException(QueryException::class);
        $this->createRecipe($articleId);
    }

    public function test_same_article_can_have_different_recipe_versions(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId, ['version' => 1]);
        $this->createRecipe($articleId, ['version' => 2]);

        $this->assertSame(
            [1, 2],
            DB::table('recetas')->where('articulo_id', $articleId)->orderBy('version')->pluck('version')->all(),
        );
    }

    public function test_different_articles_can_share_recipe_version_number(): void
    {
        $firstArticleId = $this->createArticleFixture();
        $secondArticleId = $this->createArticleFixture([
            'sku' => 'pan_alterno',
            'nombre' => 'Pan alterno',
        ]);
        $this->createRecipe($firstArticleId, ['version' => 1]);
        $this->createRecipe($secondArticleId, ['version' => 1]);

        $this->assertSame(2, DB::table('recetas')->where('version', 1)->count());
    }

    #[DataProvider('validYields')]
    public function test_valid_recipe_yield_is_accepted(string $yield): void
    {
        $recipeId = $this->createRecipe(
            $this->createArticleFixture(),
            ['rendimiento_cantidad' => $yield],
        );

        $this->assertSame($yield, DB::table('recetas')->where('id', $recipeId)->value('rendimiento_cantidad'));
    }

    public static function validYields(): array
    {
        return [
            'positive decimal' => ['1.250'],
            'smallest approved scale value' => ['0.001'],
        ];
    }

    #[DataProvider('invalidYields')]
    public function test_invalid_recipe_yield_is_rejected(string $yield): void
    {
        $articleId = $this->createArticleFixture();

        $this->expectException(QueryException::class);
        $this->createRecipe($articleId, ['rendimiento_cantidad' => $yield]);
    }

    public static function invalidYields(): array
    {
        return [
            'zero' => ['0.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_article_can_have_zero_active_recipes(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId);

        $this->assertSame(1, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', false)->count());
        $this->assertSame(0, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', true)->count());
    }

    public function test_article_can_have_one_active_recipe(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId, ['activa' => true]);

        $this->assertSame(1, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', true)->count());
    }

    public function test_article_can_have_multiple_inactive_recipes(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId, ['version' => 1]);
        $this->createRecipe($articleId, ['version' => 2]);
        $this->createRecipe($articleId, ['version' => 3]);

        $this->assertSame(3, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', false)->count());
    }

    public function test_article_can_have_one_active_and_multiple_inactive_recipes(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId, ['version' => 1, 'activa' => true]);
        $this->createRecipe($articleId, ['version' => 2]);
        $this->createRecipe($articleId, ['version' => 3]);

        $this->assertSame(1, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', true)->count());
        $this->assertSame(2, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', false)->count());
    }

    public function test_second_active_recipe_for_same_article_is_rejected(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createRecipe($articleId, ['version' => 1, 'activa' => true]);

        $this->expectException(QueryException::class);
        $this->createRecipe($articleId, ['version' => 2, 'activa' => true]);
    }

    public function test_different_articles_can_each_have_an_active_recipe(): void
    {
        $firstArticleId = $this->createArticleFixture();
        $secondArticleId = $this->createArticleFixture([
            'sku' => 'pan_alterno',
            'nombre' => 'Pan alterno',
        ]);
        $this->createRecipe($firstArticleId, ['activa' => true]);
        $this->createRecipe($secondArticleId, ['activa' => true]);

        $this->assertSame(2, DB::table('recetas')->where('activa', true)->count());
    }

    public function test_active_recipe_can_be_replaced_after_deactivation(): void
    {
        $articleId = $this->createArticleFixture();
        $firstRecipeId = $this->createRecipe($articleId, ['version' => 1, 'activa' => true]);
        $secondRecipeId = $this->createRecipe($articleId, ['version' => 2]);

        $this->assertSame(1, DB::table('recetas')->where('id', $firstRecipeId)->update(['activa' => false]));
        $this->assertSame(1, DB::table('recetas')->where('id', $secondRecipeId)->update(['activa' => true]));

        $this->assertFalse(DB::table('recetas')->where('id', $firstRecipeId)->value('activa'));
        $this->assertTrue(DB::table('recetas')->where('id', $secondRecipeId)->value('activa'));
        $this->assertSame(1, DB::table('recetas')->where('articulo_id', $articleId)->where('activa', true)->count());
    }

    #[DataProvider('validObservations')]
    public function test_valid_recipe_observation_is_preserved(?string $observation): void
    {
        $recipeId = $this->createRecipe(
            $this->createArticleFixture(),
            ['observacion' => $observation],
        );

        $this->assertSame($observation, DB::table('recetas')->where('id', $recipeId)->value('observacion'));
    }

    public static function validObservations(): array
    {
        return [
            'null' => [null],
            'text' => ['Receta base'],
        ];
    }

    #[DataProvider('invalidObservations')]
    public function test_invalid_recipe_observation_is_rejected(string $observation): void
    {
        $articleId = $this->createArticleFixture();

        $this->expectException(QueryException::class);
        $this->createRecipe($articleId, ['observacion' => $observation]);
    }

    public static function invalidObservations(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Texto'],
            'trailing space' => ['Texto '],
        ];
    }

    public function test_recipe_rejects_missing_article(): void
    {
        $missingArticleId = $this->createArticleFixture();
        $this->assertSame(1, DB::table('articulos')->where('id', $missingArticleId)->delete());

        $this->expectException(QueryException::class);
        $this->createRecipe($missingArticleId);
    }

    private function createArticleFixture(array $overrides = []): int
    {
        return $this->createArticle($this->categoryId, $this->unitId, $overrides);
    }
}
