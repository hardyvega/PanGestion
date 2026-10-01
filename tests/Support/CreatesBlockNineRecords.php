<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockNineRecords
{
    protected function productionData(
        int $recipeId,
        int $responsibleUserId,
        int $creatorUserId,
        array $overrides = [],
    ): array {
        return array_merge([
            'receta_id' => $recipeId,
            'usuario_responsable_id' => $responsibleUserId,
            'usuario_creador_id' => $creatorUserId,
        ], $overrides);
    }

    protected function createProduction(
        int $recipeId,
        int $responsibleUserId,
        int $creatorUserId,
        array $overrides = [],
    ): int {
        return (int) DB::table('producciones')->insertGetId(
            $this->productionData($recipeId, $responsibleUserId, $creatorUserId, $overrides),
        );
    }

    protected function productionDetailData(
        int $productionId,
        int $articleId,
        array $overrides = [],
    ): array {
        return array_merge([
            'produccion_id' => $productionId,
            'articulo_id' => $articleId,
            'articulo_sku_snapshot' => 'pan_prueba',
            'articulo_nombre_snapshot' => 'Pan de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
            'cantidad_teorica' => '1.000',
        ], $overrides);
    }

    protected function createProductionDetail(
        int $productionId,
        int $articleId,
        array $overrides = [],
    ): int {
        return (int) DB::table('detalle_producciones')->insertGetId(
            $this->productionDetailData($productionId, $articleId, $overrides),
        );
    }

    protected function productionConsumptionData(
        int $productionId,
        int $componentId,
        ?int $recipeDetailId = null,
        ?string $theoreticalQuantity = null,
        array $overrides = [],
    ): array {
        return array_merge([
            'produccion_id' => $productionId,
            'detalle_receta_id' => $recipeDetailId,
            'articulo_componente_id' => $componentId,
            'articulo_sku_snapshot' => 'harina_prueba',
            'articulo_nombre_snapshot' => 'Harina de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
            'cantidad_teorica' => $theoreticalQuantity,
        ], $overrides);
    }

    protected function createProductionConsumption(
        int $productionId,
        int $componentId,
        ?int $recipeDetailId = null,
        ?string $theoreticalQuantity = null,
        array $overrides = [],
    ): int {
        return (int) DB::table('consumos_produccion')->insertGetId(
            $this->productionConsumptionData(
                $productionId,
                $componentId,
                $recipeDetailId,
                $theoreticalQuantity,
                $overrides,
            ),
        );
    }

    protected function counterOutputData(
        int $productionDetailId,
        int $responsibleUserId,
        int $registrarUserId,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        return array_merge([
            'detalle_produccion_id' => $productionDetailId,
            'usuario_responsable_id' => $responsibleUserId,
            'usuario_registrador_id' => $registrarUserId,
            'clave_idempotencia' => $idempotencyKey,
            'cantidad' => '1.000',
        ], $overrides);
    }

    protected function createCounterOutput(
        int $productionDetailId,
        int $responsibleUserId,
        int $registrarUserId,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('salidas_meson')->insertGetId(
            $this->counterOutputData(
                $productionDetailId,
                $responsibleUserId,
                $registrarUserId,
                $idempotencyKey,
                $overrides,
            ),
        );
    }
}
