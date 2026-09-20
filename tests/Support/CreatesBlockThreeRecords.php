<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockThreeRecords
{
    protected function articleData(int $categoryId, int $unitId, array $overrides = []): array
    {
        return array_merge([
            'sku' => 'pan_prueba',
            'nombre' => 'Pan de prueba',
            'tipo_articulo' => 'elaborado',
            'categoria_articulo_id' => $categoryId,
            'unidad_id' => $unitId,
        ], $overrides);
    }

    protected function createArticle(int $categoryId, int $unitId, array $overrides = []): int
    {
        return (int) DB::table('articulos')->insertGetId($this->articleData($categoryId, $unitId, $overrides));
    }

    protected function priceHistoryData(int $articleId, int $userId, array $overrides = []): array
    {
        return array_merge([
            'articulo_id' => $articleId,
            'usuario_id' => $userId,
            'precio_anterior' => null,
            'precio_nuevo' => '1500.00',
        ], $overrides);
    }

    protected function createPriceHistory(int $articleId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('historial_precios_venta')->insertGetId(
            $this->priceHistoryData($articleId, $userId, $overrides),
        );
    }
}
