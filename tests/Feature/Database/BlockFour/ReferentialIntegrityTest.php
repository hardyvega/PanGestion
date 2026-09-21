<?php

namespace Tests\Feature\Database\BlockFour;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_article_linked_to_supplier_cannot_be_deleted(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createArticleSupplier($articleId, $this->createSupplier());

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $articleId)->delete();
    }

    public function test_supplier_linked_to_article_cannot_be_deleted(): void
    {
        $supplierId = $this->createSupplier();
        $this->createArticleSupplier($this->createArticleFixture(), $supplierId);

        $this->expectException(QueryException::class);
        DB::table('proveedores')->where('id', $supplierId)->delete();
    }

    public function test_article_with_cost_history_cannot_be_deleted(): void
    {
        $articleId = $this->createArticleFixture();
        $this->createArticleCostHistory($articleId, $this->createUser($this->createRole()));

        $this->expectException(QueryException::class);
        DB::table('articulos')->where('id', $articleId)->delete();
    }

    public function test_supplier_with_cost_history_cannot_be_deleted(): void
    {
        $supplierId = $this->createSupplier();
        $this->createArticleCostHistory(
            $this->createArticleFixture(),
            $this->createUser($this->createRole()),
            ['proveedor_id' => $supplierId],
        );

        $this->expectException(QueryException::class);
        DB::table('proveedores')->where('id', $supplierId)->delete();
    }

    public function test_user_with_cost_history_cannot_be_deleted(): void
    {
        $userId = $this->createUser($this->createRole());
        $this->createArticleCostHistory($this->createArticleFixture(), $userId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $userId)->delete();
    }

    private function createArticleFixture(): int
    {
        return $this->createArticle($this->createArticleCategory(), $this->createUnitOfMeasure());
    }
}
