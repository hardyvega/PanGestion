<?php

namespace Tests\Feature\Database\BlockThree;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_referenced_category_cannot_be_deleted(): void
    {
        $categoryId = $this->createArticleCategory();
        $this->createArticle($categoryId, $this->createUnitOfMeasure());

        $this->expectException(QueryException::class);
        DB::table('categorias_articulo')->where('id', $categoryId)->delete();
    }

    public function test_referenced_unit_cannot_be_deleted(): void
    {
        $unitId = $this->createUnitOfMeasure();
        $this->createArticle($this->createArticleCategory(), $unitId);

        $this->expectException(QueryException::class);
        DB::table('unidades_medida')->where('id', $unitId)->delete();
    }

    public function test_article_with_history_cannot_be_deleted(): void
    {
        $articleId = $this->createArticle($this->createArticleCategory(), $this->createUnitOfMeasure());
        $this->createPriceHistory($articleId, $this->createUser($this->createRole()));

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $articleId)->delete();
    }

    public function test_user_with_history_cannot_be_deleted(): void
    {
        $userId = $this->createUser($this->createRole());
        $articleId = $this->createArticle($this->createArticleCategory(), $this->createUnitOfMeasure());
        $this->createPriceHistory($articleId, $userId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $userId)->delete();
    }
}
