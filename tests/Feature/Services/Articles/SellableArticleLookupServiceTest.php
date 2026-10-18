<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Articles;

use App\Models\Articulo;
use App\Services\Articles\SellableArticleLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class SellableArticleLookupServiceTest extends TestCase
{
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $categoryId;

    private int $unitId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
    }

    public function test_lookup_resolves_by_id_sku_name_and_barcode(): void
    {
        $byId = $this->article(['sku' => 'por_id', 'nombre' => 'Articulo por id']);
        $bySku = $this->article(['sku' => 'sku_exacto', 'nombre' => 'Articulo por sku']);
        $byName = $this->article(['sku' => 'por_nombre', 'nombre' => 'Pan integral']);
        $byBarcode = $this->article([
            'sku' => 'por_barcode',
            'nombre' => 'Articulo por barcode',
            'codigo_barras' => '0012345678905',
        ]);
        $service = $this->service();

        $this->assertSame([$byId], $service->search((string) $byId)->modelKeys());
        $this->assertSame([$bySku], $service->search('sku_exacto')->modelKeys());
        $this->assertSame([$byName], $service->search('integral')->modelKeys());
        $this->assertSame([$byBarcode], $service->search('0012345678905')->modelKeys());
    }

    public function test_lookup_priority_is_barcode_then_sku_then_id_then_name(): void
    {
        $barcode = $this->article([
            'sku' => 'barcode_article',
            'nombre' => 'No coincide por nombre',
            'codigo_barras' => 'prioridad',
        ]);
        $sku = $this->article([
            'sku' => 'prioridad',
            'nombre' => 'Coincide por sku',
        ]);

        $this->assertSame([$barcode], $this->service()->search('prioridad')->modelKeys());

        $idTarget = $this->article(['sku' => 'id_target', 'nombre' => 'Destino por id']);
        $skuForId = $this->article([
            'sku' => (string) $idTarget,
            'nombre' => 'SKU numerico prioritario',
        ]);

        $this->assertSame([$skuForId], $this->service()->search((string) $idTarget)->modelKeys());
        $this->assertNotSame($sku, $idTarget);
    }

    public function test_lookup_returns_only_active_sellable_types(): void
    {
        $allowed = $this->article(['nombre' => 'Pan vendible']);
        $this->article(['nombre' => 'Pan vendible', 'tipo_articulo' => 'materia_prima']);
        $this->article(['nombre' => 'Pan vendible', 'tipo_articulo' => 'insumo_operacional']);
        $this->article(['nombre' => 'Pan vendible', 'activo' => false]);

        $results = $this->service()->search('Pan vendible');

        $this->assertSame([$allowed], $results->modelKeys());
    }

    public function test_name_lookup_is_limited_to_twenty_in_deterministic_order(): void
    {
        for ($index = 20; $index >= 0; $index--) {
            $this->article([
                'nombre' => 'Coincidencia '.$index,
                'sku' => 'coincidencia_'.$index,
            ]);
        }

        $expected = Articulo::query()
            ->where('activo', true)
            ->whereIn('tipo_articulo', ['elaborado', 'reventa'])
            ->where('nombre', 'ilike', '%Coincidencia%')
            ->orderBy('nombre')
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->all();

        $actual = $this->service()->search('Coincidencia')->modelKeys();

        $this->assertCount(20, $actual);
        $this->assertSame($expected, $actual);
    }

    public function test_lookup_allows_reventa_and_returns_empty_for_missing_terms(): void
    {
        $reventa = $this->article([
            'tipo_articulo' => 'reventa',
            'sku' => 'reventa_01',
            'nombre' => 'Producto revendido',
        ]);

        $this->assertSame([$reventa], $this->service()->search('reventa_01')->modelKeys());
        $this->assertCount(0, $this->service()->search('no-existe'));
        $this->assertCount(0, $this->service()->search(''));
    }

    public function test_lookup_treats_barcode_as_text_and_preserves_leading_zeroes(): void
    {
        $articleId = $this->article([
            'sku' => 'barcode_textual',
            'codigo_barras' => '00000042',
        ]);

        $result = $this->service()->search('00000042');

        $this->assertSame([$articleId], $result->modelKeys());
        $this->assertSame('00000042', $result->first()->codigo_barras);
        $this->assertSame('00000042', DB::table('articulos')->where('id', $articleId)->value('codigo_barras'));
    }

    private function service(): SellableArticleLookupService
    {
        return $this->app->make(SellableArticleLookupService::class);
    }

    private function article(array $overrides = []): int
    {
        $this->sequence++;

        return $this->createArticle($this->categoryId, $this->unitId, array_merge([
            'sku' => 'lookup_'.$this->sequence,
            'nombre' => 'Articulo lookup '.$this->sequence,
            'tipo_articulo' => 'elaborado',
            'activo' => true,
        ], $overrides));
    }
}
