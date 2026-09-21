<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockFiveRecords
{
    protected function recipeData(int $articleId, array $overrides = []): array
    {
        return array_merge([
            'articulo_id' => $articleId,
            'version' => 1,
            'rendimiento_cantidad' => '1.000',
        ], $overrides);
    }

    protected function createRecipe(int $articleId, array $overrides = []): int
    {
        return (int) DB::table('recetas')->insertGetId($this->recipeData($articleId, $overrides));
    }

    protected function recipeDetailData(int $recipeId, int $componentId, array $overrides = []): array
    {
        return array_merge([
            'receta_id' => $recipeId,
            'articulo_componente_id' => $componentId,
            'cantidad' => '1.000',
        ], $overrides);
    }

    protected function createRecipeDetail(int $recipeId, int $componentId, array $overrides = []): int
    {
        return (int) DB::table('detalle_recetas')->insertGetId(
            $this->recipeDetailData($recipeId, $componentId, $overrides),
        );
    }
}
