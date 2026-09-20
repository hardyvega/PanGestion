<?php

namespace Tests\Feature\Database\BlockTwo;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ArticleCategoryConstraintsTest extends TestCase
{
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_article_category_can_be_created_with_database_defaults(): void
    {
        $categoryId = $this->createArticleCategory(['descripcion' => null]);

        $category = DB::table('categorias_articulo')->where('id', $categoryId)->first();

        $this->assertNotNull($category);
        $this->assertSame('panes', $category->codigo);
        $this->assertSame('Panes', $category->nombre);
        $this->assertNull($category->descripcion);
        $this->assertTrue((bool) $category->activo);
        $this->assertNotNull($category->created_at);
        $this->assertNotNull($category->updated_at);
    }

    public function test_identical_valid_article_category_code_cannot_be_duplicated(): void
    {
        $this->createArticleCategory();

        $this->expectException(QueryException::class);

        DB::table('categorias_articulo')->insert($this->articleCategoryData([
            'nombre' => 'Bolleria',
        ]));
    }

    public function test_article_category_name_is_unique_case_insensitively(): void
    {
        $this->createArticleCategory();

        $this->expectException(QueryException::class);

        DB::table('categorias_articulo')->insert($this->articleCategoryData([
            'codigo' => 'panes_alternos',
            'nombre' => 'PANES',
        ]));
    }

    #[DataProvider('invalidArticleCategoryCodeValues')]
    public function test_article_category_code_checks_reject_invalid_values(string $codigo): void
    {
        $this->expectException(QueryException::class);

        DB::table('categorias_articulo')->insert($this->articleCategoryData(['codigo' => $codigo]));
    }

    public static function invalidArticleCategoryCodeValues(): array
    {
        return [
            'empty code' => [''],
            'code with surrounding whitespace' => [' panes'],
            'uppercase code' => ['Panes'],
            'invalid code format' => ['1panes'],
        ];
    }

    #[DataProvider('invalidArticleCategoryNameValues')]
    public function test_article_category_name_checks_reject_invalid_values(string $nombre): void
    {
        $this->expectException(QueryException::class);

        DB::table('categorias_articulo')->insert($this->articleCategoryData(['nombre' => $nombre]));
    }

    public static function invalidArticleCategoryNameValues(): array
    {
        return [
            'empty name' => [''],
            'name with surrounding whitespace' => [' Panes'],
        ];
    }
}
