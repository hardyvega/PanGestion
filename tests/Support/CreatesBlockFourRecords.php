<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockFourRecords
{
    protected function clientData(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Cliente de prueba',
        ], $overrides);
    }

    protected function createClient(array $overrides = []): int
    {
        return (int) DB::table('clientes')->insertGetId($this->clientData($overrides));
    }

    protected function supplierData(array $overrides = []): array
    {
        return array_merge([
            'codigo' => 'proveedor_prueba',
            'nombre' => 'Proveedor de prueba',
        ], $overrides);
    }

    protected function createSupplier(array $overrides = []): int
    {
        return (int) DB::table('proveedores')->insertGetId($this->supplierData($overrides));
    }

    protected function articleSupplierData(int $articleId, int $supplierId, array $overrides = []): array
    {
        return array_merge([
            'articulo_id' => $articleId,
            'proveedor_id' => $supplierId,
        ], $overrides);
    }

    protected function createArticleSupplier(int $articleId, int $supplierId, array $overrides = []): int
    {
        return (int) DB::table('articulo_proveedor')->insertGetId(
            $this->articleSupplierData($articleId, $supplierId, $overrides),
        );
    }

    protected function articleCostHistoryData(int $articleId, int $userId, array $overrides = []): array
    {
        return array_merge([
            'articulo_id' => $articleId,
            'proveedor_id' => null,
            'costo_anterior' => null,
            'costo_nuevo' => '1000.00',
            'usuario_id' => $userId,
        ], $overrides);
    }

    protected function createArticleCostHistory(int $articleId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('historial_costos_articulo')->insertGetId(
            $this->articleCostHistoryData($articleId, $userId, $overrides),
        );
    }
}
