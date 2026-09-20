<?php

namespace Tests\Feature\Database\BlockThree;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ArticleConstraintsTest extends TestCase
{
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

    public function test_article_can_be_created_with_defaults(): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId);
        $article = DB::table('articulos')->where('id', $id)->first();

        $this->assertNotNull($article);
        $this->assertSame($id, $article->id);
        $this->assertSame('pan_prueba', $article->sku);
        $this->assertSame('Pan de prueba', $article->nombre);
        $this->assertSame('elaborado', $article->tipo_articulo);
        $this->assertSame($this->categoryId, $article->categoria_articulo_id);
        $this->assertSame($this->unitId, $article->unidad_id);
        $this->assertNull($article->descripcion);
        $this->assertNull($article->codigo_barras);
        $this->assertNull($article->precio_venta_actual);
        $this->assertNull($article->imagen_ruta);
        $this->assertSame('0.000', $article->stock_actual);
        $this->assertSame('0.000', $article->stock_minimo);
        $this->assertTrue($article->activo);
        $this->assertNotNull($article->created_at);
        $this->assertNotNull($article->updated_at);
    }

    #[DataProvider('validSkus')]
    public function test_valid_sku_is_preserved(string $sku): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, ['sku' => $sku]);

        $this->assertSame($sku, DB::table('articulos')->where('id', $id)->value('sku'));
    }

    public static function validSkus(): array
    {
        return [
            'alphabetic start' => ['pan-01_a'],
            'numeric start' => ['001-pan_a'],
        ];
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $this->createArticle($this->categoryId, $this->unitId);
        $data = $this->articleData($this->categoryId, $this->unitId, ['nombre' => 'Otro articulo']);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    #[DataProvider('invalidSkus')]
    public function test_invalid_sku_is_rejected(string $sku): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, ['sku' => $sku]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function invalidSkus(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' pan01'],
            'trailing space' => ['pan01 '],
            'uppercase' => ['Pan01'],
            'invalid format' => ['pan.01'],
        ];
    }

    public function test_duplicate_article_names_are_allowed(): void
    {
        $firstId = $this->createArticle($this->categoryId, $this->unitId);
        $secondId = $this->createArticle($this->categoryId, $this->unitId, ['sku' => 'pan_alterno']);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('articulos')->where('nombre', 'Pan de prueba')->count());
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_article_name_is_rejected(string $name): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, ['nombre' => $name]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Pan'],
            'trailing space' => ['Pan '],
        ];
    }

    #[DataProvider('approvedArticleTypes')]
    public function test_each_approved_article_type_is_accepted(string $type): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'prueba_'.$type,
            'tipo_articulo' => $type,
        ]);

        $this->assertSame($type, DB::table('articulos')->where('id', $id)->value('tipo_articulo'));
    }

    public static function approvedArticleTypes(): array
    {
        return [
            'elaborado' => ['elaborado'],
            'reventa' => ['reventa'],
            'materia prima' => ['materia_prima'],
            'insumo operacional' => ['insumo_operacional'],
        ];
    }

    public function test_unapproved_article_type_is_rejected(): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, ['tipo_articulo' => 'servicio']);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    #[DataProvider('articleParents')]
    public function test_article_rejects_missing_parent(string $column, string $parentTable): void
    {
        $missingId = (int) DB::table($parentTable)->max('id') + 1;
        $this->assertFalse(DB::table($parentTable)->where('id', $missingId)->exists());
        $data = $this->articleData($this->categoryId, $this->unitId, [$column => $missingId]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function articleParents(): array
    {
        return [
            'category' => ['categoria_articulo_id', 'categorias_articulo'],
            'unit' => ['unidad_id', 'unidades_medida'],
        ];
    }

    public function test_multiple_articles_can_have_null_barcode(): void
    {
        $this->createArticle($this->categoryId, $this->unitId, ['codigo_barras' => null]);
        $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_alterno',
            'codigo_barras' => null,
        ]);

        $this->assertSame(2, DB::table('articulos')->whereNull('codigo_barras')->count());
    }

    #[DataProvider('validBarcodes')]
    public function test_barcode_text_is_preserved(string $barcode): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, ['codigo_barras' => $barcode]);

        $this->assertSame($barcode, DB::table('articulos')->where('id', $id)->value('codigo_barras'));
    }

    public static function validBarcodes(): array
    {
        return [
            'alphanumeric' => ['abc123'],
            'leading zeros' => ['001234567890'],
            'visible capitalization' => ['AbC123'],
            'internal space' => ['AB 001'],
        ];
    }

    #[DataProvider('invalidBarcodes')]
    public function test_invalid_barcode_is_rejected(string $barcode): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, ['codigo_barras' => $barcode]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function invalidBarcodes(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' ABC'],
            'trailing space' => ['ABC '],
        ];
    }

    public function test_duplicate_barcode_is_rejected(): void
    {
        $this->createArticle($this->categoryId, $this->unitId, ['codigo_barras' => 'ABC001']);
        $data = $this->articleData($this->categoryId, $this->unitId, [
            'sku' => 'pan_alterno',
            'codigo_barras' => 'ABC001',
        ]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public function test_barcode_uniqueness_is_case_sensitive(): void
    {
        $firstId = $this->createArticle($this->categoryId, $this->unitId, ['codigo_barras' => 'AbC001']);
        $secondId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_alterno',
            'codigo_barras' => 'abc001',
        ]);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame('AbC001', DB::table('articulos')->where('id', $firstId)->value('codigo_barras'));
        $this->assertSame('abc001', DB::table('articulos')->where('id', $secondId)->value('codigo_barras'));
        $this->assertSame(2, DB::table('articulos')->count());
    }

    #[DataProvider('validCurrentPrices')]
    public function test_valid_current_price_is_accepted(?string $price): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, ['precio_venta_actual' => $price]);

        $this->assertSame($price, DB::table('articulos')->where('id', $id)->value('precio_venta_actual'));
    }

    public static function validCurrentPrices(): array
    {
        return [
            'null' => [null],
            'zero' => ['0.00'],
            'positive' => ['1500.25'],
        ];
    }

    #[DataProvider('invalidCurrentPrices')]
    public function test_invalid_current_price_is_rejected(string $price): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, ['precio_venta_actual' => $price]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function invalidCurrentPrices(): array
    {
        return [
            'negative' => ['-0.01'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('validStockValues')]
    public function test_valid_stock_value_is_accepted(string $column, string $quantity): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, [$column => $quantity]);

        $this->assertSame($quantity, DB::table('articulos')->where('id', $id)->value($column));
    }

    public static function validStockValues(): array
    {
        return [
            'actual zero' => ['stock_actual', '0.000'],
            'actual positive' => ['stock_actual', '12.375'],
            'minimum zero' => ['stock_minimo', '0.000'],
            'minimum positive' => ['stock_minimo', '12.375'],
        ];
    }

    #[DataProvider('invalidStockValues')]
    public function test_invalid_stock_value_is_rejected(string $column, string $quantity): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, [$column => $quantity]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function invalidStockValues(): array
    {
        return [
            'actual negative' => ['stock_actual', '-0.001'],
            'actual NaN' => ['stock_actual', 'NaN'],
            'minimum negative' => ['stock_minimo', '-0.001'],
            'minimum NaN' => ['stock_minimo', 'NaN'],
        ];
    }

    public function test_stock_below_minimum_is_allowed(): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, [
            'stock_actual' => '1.250',
            'stock_minimo' => '5.000',
        ]);
        $article = DB::table('articulos')->where('id', $id)->first();

        $this->assertNotNull($article);
        $this->assertSame('1.250', $article->stock_actual);
        $this->assertSame('5.000', $article->stock_minimo);
    }

    #[DataProvider('validImagePaths')]
    public function test_valid_image_path_is_preserved(?string $path): void
    {
        $id = $this->createArticle($this->categoryId, $this->unitId, ['imagen_ruta' => $path]);

        $this->assertSame($path, DB::table('articulos')->where('id', $id)->value('imagen_ruta'));
    }

    public static function validImagePaths(): array
    {
        return [
            'null' => [null],
            'relative path' => ['articulos/pan.jpg'],
        ];
    }

    #[DataProvider('invalidImagePaths')]
    public function test_invalid_image_path_is_rejected(string $path): void
    {
        $data = $this->articleData($this->categoryId, $this->unitId, ['imagen_ruta' => $path]);

        $this->expectException(QueryException::class);
        DB::table('articulos')->insert($data);
    }

    public static function invalidImagePaths(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' articulos/pan.jpg'],
            'trailing space' => ['articulos/pan.jpg '],
        ];
    }
}
