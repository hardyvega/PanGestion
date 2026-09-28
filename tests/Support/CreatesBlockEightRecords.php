<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockEightRecords
{
    protected function saleSequenceData(string $commercialDate, array $overrides = []): array
    {
        return array_merge([
            'fecha_comercial' => $commercialDate,
        ], $overrides);
    }

    protected function createSaleSequence(string $commercialDate, array $overrides = []): string
    {
        $data = $this->saleSequenceData($commercialDate, $overrides);
        DB::table('correlativos_venta_diarios')->insert($data);

        return (string) $data['fecha_comercial'];
    }

    protected function saleData(
        int $userId,
        int $cashSessionId,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        return array_merge([
            'fecha_comercial' => '2026-09-24',
            'numero_diario' => 1,
            'clave_idempotencia' => $idempotencyKey,
            'usuario_id' => $userId,
            'sesion_caja_id' => $cashSessionId,
            'total' => '1000.00',
            'completada_en' => '2026-09-24 12:00:00-03',
        ], $overrides);
    }

    protected function createSale(
        int $userId,
        int $cashSessionId,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('ventas')->insertGetId(
            $this->saleData($userId, $cashSessionId, $idempotencyKey, $overrides),
        );
    }

    protected function saleDetailData(int $saleId, int $articleId, array $overrides = []): array
    {
        return array_merge([
            'venta_id' => $saleId,
            'articulo_id' => $articleId,
            'articulo_sku_snapshot' => 'pan_prueba',
            'articulo_nombre_snapshot' => 'Pan de prueba',
            'unidad_codigo_snapshot' => 'kg',
            'unidad_simbolo_snapshot' => 'kg',
            'cantidad' => '1.000',
            'precio_unitario' => '1000.00',
            'subtotal' => '1000.00',
        ], $overrides);
    }

    protected function createSaleDetail(int $saleId, int $articleId, array $overrides = []): int
    {
        return (int) DB::table('detalle_ventas')->insertGetId(
            $this->saleDetailData($saleId, $articleId, $overrides),
        );
    }

    protected function salePaymentData(
        int $saleId,
        int $paymentMethodId,
        int $cashSessionId,
        int $userId,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        return array_merge([
            'venta_id' => $saleId,
            'metodo_pago_id' => $paymentMethodId,
            'sesion_caja_id' => $cashSessionId,
            'usuario_id' => $userId,
            'clave_idempotencia' => $idempotencyKey,
            'tipo' => 'cobro',
            'monto' => '1.00',
        ], $overrides);
    }

    protected function createSalePayment(
        int $saleId,
        int $paymentMethodId,
        int $cashSessionId,
        int $userId,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('pagos_venta')->insertGetId(
            $this->salePaymentData(
                $saleId,
                $paymentMethodId,
                $cashSessionId,
                $userId,
                $idempotencyKey,
                $overrides,
            ),
        );
    }
}
