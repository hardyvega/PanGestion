<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockSevenRecords
{
    protected function orderData(int $clientId, int $creatorId, array $overrides = []): array
    {
        return array_merge([
            'cliente_id' => $clientId,
            'usuario_creador_id' => $creatorId,
        ], $overrides);
    }

    protected function createOrder(int $clientId, int $creatorId, array $overrides = []): int
    {
        return (int) DB::table('pedidos')->insertGetId($this->orderData($clientId, $creatorId, $overrides));
    }

    protected function orderDetailData(int $orderId, int $articleId, array $overrides = []): array
    {
        return array_merge([
            'pedido_id' => $orderId,
            'articulo_id' => $articleId,
            'cantidad' => '1.000',
            'precio_unitario' => '1000.00',
        ], $overrides);
    }

    protected function createOrderDetail(int $orderId, int $articleId, array $overrides = []): int
    {
        return (int) DB::table('detalle_pedidos')->insertGetId(
            $this->orderDetailData($orderId, $articleId, $overrides),
        );
    }

    protected function orderPaymentData(
        int $orderId,
        int $paymentMethodId,
        int $cashSessionId,
        int $userId,
        string $idempotencyKey,
        array $overrides = [],
    ): array {
        return array_merge([
            'pedido_id' => $orderId,
            'metodo_pago_id' => $paymentMethodId,
            'sesion_caja_id' => $cashSessionId,
            'usuario_id' => $userId,
            'clave_idempotencia' => $idempotencyKey,
            'tipo' => 'cobro',
            'monto' => '1.00',
        ], $overrides);
    }

    protected function createOrderPayment(
        int $orderId,
        int $paymentMethodId,
        int $cashSessionId,
        int $userId,
        string $idempotencyKey,
        array $overrides = [],
    ): int {
        return (int) DB::table('pagos_pedido')->insertGetId(
            $this->orderPaymentData(
                $orderId,
                $paymentMethodId,
                $cashSessionId,
                $userId,
                $idempotencyKey,
                $overrides,
            ),
        );
    }
}
