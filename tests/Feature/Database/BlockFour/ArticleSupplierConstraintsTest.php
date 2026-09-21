<?php

namespace Tests\Feature\Database\BlockFour;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ArticleSupplierConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $articleId;

    private int $categoryId;

    private int $supplierId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
        $this->supplierId = $this->createSupplier();
    }

    public function test_article_supplier_can_be_created_with_defaults(): void
    {
        $id = $this->createArticleSupplier($this->articleId, $this->supplierId);
        $relation = DB::table('articulo_proveedor')->where('id', $id)->first();

        $this->assertNotNull($relation);
        $this->assertSame($id, $relation->id);
        $this->assertSame($this->articleId, $relation->articulo_id);
        $this->assertSame($this->supplierId, $relation->proveedor_id);
        $this->assertNull($relation->codigo_articulo_proveedor);
        $this->assertTrue($relation->activo);
        $this->assertNotNull($relation->created_at);
        $this->assertNotNull($relation->updated_at);
    }

    public function test_duplicate_article_supplier_pair_is_rejected(): void
    {
        $this->createArticleSupplier($this->articleId, $this->supplierId);

        $this->expectException(QueryException::class);
        $this->createArticleSupplier($this->articleId, $this->supplierId, [
            'codigo_articulo_proveedor' => 'OTRO-CODIGO',
        ]);
    }

    #[DataProvider('distinctArticleSupplierScenarios')]
    public function test_distinct_article_supplier_pair_is_allowed(string $scenario): void
    {
        $this->createArticleSupplier($this->articleId, $this->supplierId);

        if ($scenario === 'same_article_other_supplier') {
            $articleId = $this->articleId;
            $supplierId = $this->createSupplier([
                'codigo' => 'proveedor_alterno',
                'nombre' => 'Proveedor alterno',
            ]);
        } else {
            $articleId = $this->createArticle($this->categoryId, $this->unitId, [
                'sku' => 'pan_alterno',
                'nombre' => 'Pan alterno',
            ]);
            $supplierId = $this->supplierId;
        }

        $secondId = $this->createArticleSupplier($articleId, $supplierId);

        $this->assertSame(2, DB::table('articulo_proveedor')->count());
        $this->assertSame($articleId, DB::table('articulo_proveedor')->where('id', $secondId)->value('articulo_id'));
        $this->assertSame($supplierId, DB::table('articulo_proveedor')->where('id', $secondId)->value('proveedor_id'));
    }

    public static function distinctArticleSupplierScenarios(): array
    {
        return [
            'same article with another supplier' => ['same_article_other_supplier'],
            'another article with same supplier' => ['other_article_same_supplier'],
        ];
    }

    public function test_inactive_article_supplier_pair_still_rejects_duplicate(): void
    {
        $id = $this->createArticleSupplier($this->articleId, $this->supplierId);
        $updated = DB::table('articulo_proveedor')->where('id', $id)->update(['activo' => false]);
        $this->assertSame(1, $updated);

        $this->expectException(QueryException::class);
        $this->createArticleSupplier($this->articleId, $this->supplierId);
    }

    #[DataProvider('articleSupplierParents')]
    public function test_article_supplier_rejects_missing_parent(string $column, string $parentTable): void
    {
        $missingId = (int) DB::table($parentTable)->max('id') + 1;
        $this->assertFalse(DB::table($parentTable)->where('id', $missingId)->exists());
        $data = $this->articleSupplierData($this->articleId, $this->supplierId, [$column => $missingId]);

        $this->expectException(QueryException::class);
        DB::table('articulo_proveedor')->insert($data);
    }

    public static function articleSupplierParents(): array
    {
        return [
            'article' => ['articulo_id', 'articulos'],
            'supplier' => ['proveedor_id', 'proveedores'],
        ];
    }

    #[DataProvider('approvedExternalSupplierCodes')]
    public function test_approved_external_supplier_code_is_preserved(?string $code): void
    {
        $id = $this->createArticleSupplier($this->articleId, $this->supplierId, [
            'codigo_articulo_proveedor' => $code,
        ]);

        $this->assertSame(
            $code,
            DB::table('articulo_proveedor')->where('id', $id)->value('codigo_articulo_proveedor'),
        );
    }

    public static function approvedExternalSupplierCodes(): array
    {
        return [
            'null' => [null],
            'plain text' => ['EXT-001'],
            'visible capitalization' => ['AbC-001'],
            'internal spaces' => ['ABC 001'],
        ];
    }

    #[DataProvider('invalidExternalSupplierCodes')]
    public function test_invalid_external_supplier_code_is_rejected(string $code): void
    {
        $this->expectException(QueryException::class);
        $this->createArticleSupplier($this->articleId, $this->supplierId, [
            'codigo_articulo_proveedor' => $code,
        ]);
    }

    public static function invalidExternalSupplierCodes(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' EXT-001'],
            'trailing space' => ['EXT-001 '],
        ];
    }
}
