<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockTenRecords
{
    protected function inventoryCountData(
        int $creatorUserId,
        int $responsibleUserId,
        array $overrides = [],
    ): array {
        return array_merge([
            'usuario_creador_id' => $creatorUserId,
            'usuario_responsable_id' => $responsibleUserId,
        ], $overrides);
    }

    protected function createInventoryCount(
        int $creatorUserId,
        int $responsibleUserId,
        array $overrides = [],
    ): int {
        return (int) DB::table('conteos_inventario')->insertGetId(
            $this->inventoryCountData($creatorUserId, $responsibleUserId, $overrides),
        );
    }

    protected function inventoryCountDetailData(
        int $inventoryCountId,
        int $articleId,
        array $overrides = [],
    ): array {
        return array_merge([
            'conteo_inventario_id' => $inventoryCountId,
            'articulo_id' => $articleId,
            'articulo_sku_snapshot' => 'pan_prueba',
            'articulo_nombre_snapshot' => 'Pan de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
        ], $overrides);
    }

    protected function createInventoryCountDetail(
        int $inventoryCountId,
        int $articleId,
        array $overrides = [],
    ): int {
        return (int) DB::table('detalle_conteos_inventario')->insertGetId(
            $this->inventoryCountDetailData($inventoryCountId, $articleId, $overrides),
        );
    }

    protected function wasteData(
        int $articleId,
        int $registrarUserId,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        return array_merge([
            'articulo_id' => $articleId,
            'usuario_registrador_id' => $registrarUserId,
            'clave_idempotencia' => $idempotencyKey,
            'articulo_sku_snapshot' => 'pan_prueba',
            'articulo_nombre_snapshot' => 'Pan de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
            'tipo_merma' => 'vencimiento',
            'cantidad' => '1.000',
        ], $overrides);
    }

    protected function createWaste(
        int $articleId,
        int $registrarUserId,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('mermas')->insertGetId(
            $this->wasteData($articleId, $registrarUserId, $idempotencyKey, $overrides),
        );
    }
}
