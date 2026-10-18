<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockElevenRecords
{
    protected function createInventoryMovementOriginRecords(
        int $userId,
        int $articleId,
        int $componentArticleId,
    ): array {
        $cashSessionId = $this->createCashSession($this->createCashRegister(), $userId);
        $saleId = $this->createSale(
            $userId,
            $cashSessionId,
            '81000000-0000-4000-8000-000000000001',
        );
        $saleDetailId = $this->createSaleDetail($saleId, $articleId);

        $receiptId = $this->createReceipt(
            $userId,
            '81000000-0000-4000-8000-000000000002',
        );
        $receiptDetailId = $this->createReceiptDetail($receiptId, $articleId);

        $recipeId = $this->createRecipe($articleId, ['activa' => true]);
        $productionId = $this->createProduction($recipeId, $userId, $userId);
        $productionDetailId = $this->createProductionDetail($productionId, $articleId);
        $productionConsumptionId = $this->createProductionConsumption(
            $productionId,
            $componentArticleId,
        );

        $inventoryCountId = $this->createInventoryCount($userId, $userId);
        $inventoryCountDetailId = $this->createInventoryCountDetail(
            $inventoryCountId,
            $articleId,
        );
        $wasteId = $this->createWaste(
            $articleId,
            $userId,
            '81000000-0000-4000-8000-000000000003',
        );

        return [
            'article_id' => $articleId,
            'component_article_id' => $componentArticleId,
            'detalle_venta_id' => $saleDetailId,
            'detalle_recepcion_mercaderia_id' => $receiptDetailId,
            'detalle_produccion_id' => $productionDetailId,
            'consumo_produccion_id' => $productionConsumptionId,
            'merma_id' => $wasteId,
            'detalle_conteo_inventario_id' => $inventoryCountDetailId,
        ];
    }

    protected function receiptData(
        int $creatorUserId,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        return array_merge([
            'clave_idempotencia' => $idempotencyKey,
            'tipo_origen' => 'otro_autorizado',
            'origen_detalle' => 'Origen autorizado',
            'usuario_creador_id' => $creatorUserId,
        ], $overrides);
    }

    protected function createReceipt(
        int $creatorUserId,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('recepciones_mercaderia')->insertGetId(
            $this->receiptData($creatorUserId, $idempotencyKey, $overrides),
        );
    }

    protected function receiptDetailData(
        int $receiptId,
        int $articleId,
        array $overrides = [],
    ): array {
        return array_merge([
            'recepcion_mercaderia_id' => $receiptId,
            'articulo_id' => $articleId,
            'articulo_sku_snapshot' => 'pan_prueba',
            'articulo_nombre_snapshot' => 'Pan de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
            'cantidad' => null,
            'costo_unitario' => null,
            'motivo_costo_cero' => null,
        ], $overrides);
    }

    protected function createReceiptDetail(
        int $receiptId,
        int $articleId,
        array $overrides = [],
    ): int {
        return (int) DB::table('detalle_recepciones_mercaderia')->insertGetId(
            $this->receiptDetailData($receiptId, $articleId, $overrides),
        );
    }

    protected function inventoryMovementData(
        int $articleId,
        int $userId,
        string $type,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        $positiveTypes = [
            'saldo_inicial',
            'anulacion_venta',
            'recepcion',
            'produccion_entrada',
            'anulacion_produccion_consumo',
            'anulacion_merma',
            'ajuste_conteo_positivo',
            'ajuste_manual_entrada',
        ];
        $isPositive = in_array($type, $positiveTypes, true);
        $requiresReason = in_array($type, [
            'saldo_inicial',
            'ajuste_manual_entrada',
            'ajuste_manual_salida',
        ], true);

        return array_merge([
            'articulo_id' => $articleId,
            'usuario_id' => $userId,
            'usuario_autorizador_id' => null,
            'tipo_movimiento' => $type,
            'cantidad_delta' => $isPositive ? '1.000' : '-1.000',
            'stock_anterior' => $isPositive ? '0.000' : '2.000',
            'stock_posterior' => '1.000',
            'clave_idempotencia' => $idempotencyKey,
            'secuencia_idempotencia' => 1,
            'motivo' => $requiresReason ? 'Ajuste autorizado' : null,
            'articulo_sku_snapshot' => 'pan_prueba',
            'articulo_nombre_snapshot' => 'Pan de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
            'detalle_venta_id' => null,
            'detalle_recepcion_mercaderia_id' => null,
            'detalle_produccion_id' => null,
            'consumo_produccion_id' => null,
            'merma_id' => null,
            'detalle_conteo_inventario_id' => null,
            'movimiento_revertido_id' => null,
        ], $overrides);
    }

    protected function createInventoryMovement(
        int $articleId,
        int $userId,
        string $type,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('movimientos_inventario')->insertGetId(
            $this->inventoryMovementData($articleId, $userId, $type, $idempotencyKey, $overrides),
        );
    }

    protected function inventoryMovementCauseOverrides(
        string $type,
        array $origins,
        ?int $reversedMovementId = null,
    ): array {
        $column = match ($type) {
            'venta', 'anulacion_venta' => 'detalle_venta_id',
            'recepcion', 'anulacion_recepcion' => 'detalle_recepcion_mercaderia_id',
            'produccion_entrada', 'anulacion_produccion_entrada' => 'detalle_produccion_id',
            'produccion_consumo', 'anulacion_produccion_consumo' => 'consumo_produccion_id',
            'merma', 'anulacion_merma' => 'merma_id',
            'ajuste_conteo_positivo', 'ajuste_conteo_negativo' => 'detalle_conteo_inventario_id',
            default => null,
        };
        $overrides = $column === null ? [] : [$column => $origins[$column]];

        if ($reversedMovementId !== null) {
            $overrides['movimiento_revertido_id'] = $reversedMovementId;
        }

        return $overrides;
    }

    protected function inventoryMovementArticleId(string $type, array $origins): int
    {
        return in_array($type, [
            'produccion_consumo',
            'anulacion_produccion_consumo',
        ], true)
            ? $origins['component_article_id']
            : $origins['article_id'];
    }

    protected function originalInventoryMovementType(string $cancellationType): string
    {
        return match ($cancellationType) {
            'anulacion_venta' => 'venta',
            'anulacion_recepcion' => 'recepcion',
            'anulacion_produccion_entrada' => 'produccion_entrada',
            'anulacion_produccion_consumo' => 'produccion_consumo',
            'anulacion_merma' => 'merma',
        };
    }

    protected function stockDailyData(int $articleId, array $overrides = []): array
    {
        return array_merge([
            'articulo_id' => $articleId,
            'fecha_comercial' => '2026-09-20',
            'stock_apertura' => '0.000',
            'total_entradas' => '1.000',
            'total_salidas' => '0.000',
            'stock_cierre' => '1.000',
            'ultimo_movimiento_id' => null,
        ], $overrides);
    }

    protected function createStockDaily(int $articleId, array $overrides = []): int
    {
        return (int) DB::table('stock_diario')->insertGetId(
            $this->stockDailyData($articleId, $overrides),
        );
    }
}
