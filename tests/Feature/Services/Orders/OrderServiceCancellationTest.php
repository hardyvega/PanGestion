<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Orders;

use App\Data\Orders\CancelOrderCommand;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Pedido;
use App\Services\Orders\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Orders\OrderServiceFixtures;
use Tests\TestCase;

final class OrderServiceCancellationTest extends TestCase
{
    use OrderServiceFixtures;
    use RefreshDatabase;

    #[DataProvider('preDeliveryStates')]
    public function test_cancel_reaches_cancelled_from_each_pre_delivery_state(OrderStatus $state): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'cancel_actor');
        $fixture = $this->createCancellableOrder($actor, $state);
        $stockBefore = DB::table('articulos')->where('id', $fixture['article_id'])->value('stock_actual');
        $salesBefore = DB::table('ventas')->count();
        $movementBefore = DB::table('movimientos_inventario')->count();
        $dailyStockBefore = DB::table('stock_diario')->count();
        $reason = 'Cliente desiste del pedido';

        $order = $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand(
                $fixture['order_id'],
                'c1000000-0000-4000-8000-000000000001',
                $reason,
            ),
        );

        $this->assertInstanceOf(Pedido::class, $order);
        $this->assertSame(OrderStatus::Cancelled, $order->estado);
        $this->assertNotNull($order->cancelado_en);
        $this->assertNull($order->entregado_en);
        $transition = DB::table('transiciones_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->orderByDesc('id')
            ->first();
        $this->assertSame($state->value, $transition->estado_anterior);
        $this->assertSame(OrderStatus::Cancelled->value, $transition->estado_nuevo);
        $this->assertSame($reason, $transition->motivo);
        $this->assertSame((int) $actor->getKey(), $transition->usuario_id);
        $this->assertSame('c1000000-0000-4000-8000-000000000001', $transition->clave_idempotencia);

        $payments = DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $payments);
        $this->assertSame(PaymentType::Charge->value, $payments[0]->tipo);
        $this->assertSame('10.00', $payments[0]->monto);
        $this->assertSame(PaymentType::Refund->value, $payments[1]->tipo);
        $this->assertSame('10.00', $payments[1]->monto);
        $this->assertSame($fixture['payment_method_id'], $payments[1]->metodo_pago_id);
        $this->assertSame($fixture['cash_session_id'], $payments[1]->sesion_caja_id);
        $this->assertSame((int) $actor->getKey(), $payments[1]->usuario_id);
        $this->assertSame('c1000000-0000-4000-8000-000000000001', $payments[1]->clave_idempotencia);
        $this->assertSame($stockBefore, DB::table('articulos')->where('id', $fixture['article_id'])->value('stock_actual'));
        $this->assertSame($salesBefore, DB::table('ventas')->count());
        $this->assertSame($movementBefore, DB::table('movimientos_inventario')->count());
        $this->assertSame($dailyStockBefore, DB::table('stock_diario')->count());
    }

    public static function preDeliveryStates(): array
    {
        return [
            'pending' => [OrderStatus::Pending],
            'confirmed' => [OrderStatus::Confirmed],
            'in preparation' => [OrderStatus::InPreparation],
            'ready' => [OrderStatus::Ready],
        ];
    }

    public function test_cancel_retry_with_same_uuid_and_same_intention_does_not_duplicate_history_or_refund(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'cancel_retry_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending);
        $command = new CancelOrderCommand(
            $fixture['order_id'],
            'c1000000-0000-4000-8000-000000000002',
            'Cliente desiste del pedido',
        );

        $first = $this->orderService()->cancel($actor, $command);
        $second = $this->orderService()->cancel($actor, $command);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(OrderStatus::Cancelled, $second->estado);
        $this->assertSame(2, DB::table('transiciones_pedido')->where('pedido_id', $fixture['order_id'])->count());
        $this->assertSame(2, DB::table('pagos_pedido')->where('pedido_id', $fixture['order_id'])->count());
        $this->assertSame(1, DB::table('transiciones_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('clave_idempotencia', $command->commandUuid)
            ->count());
        $this->assertSame(1, DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('clave_idempotencia', $command->commandUuid)
            ->where('tipo', PaymentType::Refund->value)
            ->count());
    }

    public function test_cancel_retry_with_same_uuid_and_different_reason_conflicts_without_mutation(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'cancel_conflict_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending);
        $uuid = 'c1000000-0000-4000-8000-000000000003';

        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand($fixture['order_id'], $uuid, 'Primer motivo'),
        );
        $before = $this->orderStateSnapshot();

        $this->expectException(IdempotencyConflictException::class);
        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand($fixture['order_id'], $uuid, 'Motivo distinto'),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_cancel_of_delivered_order_is_rejected_and_does_not_modify_linked_sale(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'delivered_cancel_actor');
        $clientId = $this->createOrderClient('delivered_cancel_client');
        $cashSessionId = $this->createOrderCashSession($actor, 'delivered_cancel_cash');
        $orderId = $this->createHistoricalOrder(
            $actor,
            $clientId,
            OrderStatus::Delivered,
            'c1000000-0000-4000-8000-000000000004',
        );
        $saleId = $this->createLinkedHistoricalSale(
            $actor,
            $orderId,
            $cashSessionId,
            'c2000000-0000-4000-8000-000000000001',
        );
        $before = $this->orderStateSnapshot();

        $this->expectException(InventoryDomainException::class);
        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand(
                $orderId,
                'c1000000-0000-4000-8000-000000000005',
                'Cancelacion tardia',
            ),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
        $this->assertSame(1, DB::table('ventas')->where('id', $saleId)->count());
    }

    public function test_cancel_of_already_cancelled_order_with_new_uuid_is_rejected(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'already_cancelled_actor');
        $clientId = $this->createOrderClient('already_cancelled_client');
        $orderId = $this->createHistoricalOrder(
            $actor,
            $clientId,
            OrderStatus::Cancelled,
            'c1000000-0000-4000-8000-000000000006',
            'Cancelacion anterior',
        );
        $before = $this->orderStateSnapshot();

        $this->expectException(InventoryDomainException::class);
        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand(
                $orderId,
                'c1000000-0000-4000-8000-000000000007',
                'Segundo intento',
            ),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_cancel_requires_orders_cancel_permission_and_preserves_payments(): void
    {
        $actor = $this->createOrderActor([], 'unauthorized_cancel_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending);
        $before = $this->orderStateSnapshot();

        $this->expectException(AuthorizationException::class);
        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand(
                $fixture['order_id'],
                'c1000000-0000-4000-8000-000000000008',
                'Cliente desiste del pedido',
            ),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_cancel_refund_is_limited_to_the_net_single_payment_balance(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'net_refund_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending, false);
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000001',
            '10.00',
            PaymentType::Charge,
        );
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000002',
            '4.00',
            PaymentType::Refund,
        );

        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand(
                $fixture['order_id'],
                'c1000000-0000-4000-8000-000000000009',
                'Devolucion parcial y cancelacion',
            ),
        );

        $refunds = DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('tipo', PaymentType::Refund->value)
            ->orderBy('id')
            ->pluck('monto')
            ->all();
        $this->assertSame(['4.00', '6.00'], $refunds);
    }

    public function test_cancel_rejects_an_incompatible_refund_uuid_collision_without_mutation(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'refund_collision_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending, false);
        $cancelUuid = 'c1000000-0000-4000-8000-000000000010';
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            $cancelUuid,
            '10.00',
            PaymentType::Charge,
        );
        $before = $this->orderStateSnapshot();

        $caught = null;
        try {
            $this->orderService()->cancel(
                $actor,
                new CancelOrderCommand($fixture['order_id'], $cancelUuid, 'Colision de devolucion'),
            );
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(IdempotencyConflictException::class, $caught);
        $this->assertSame($before, $this->orderStateSnapshot());
        $this->assertSame(0, DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('clave_idempotencia', $cancelUuid)
            ->where('tipo', PaymentType::Refund->value)
            ->count());
        $this->assertSame(0, DB::table('transiciones_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('clave_idempotencia', $cancelUuid)
            ->count());
    }

    public function test_cancel_rejects_mixed_positive_payment_balances_before_any_mutation(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'mixed_refund_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending, false);
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000010',
            '3.00',
            PaymentType::Charge,
        );
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000011',
            '2.00',
            PaymentType::Charge,
        );
        $before = $this->orderStateSnapshot();

        $caught = null;
        try {
            $this->orderService()->cancel(
                $actor,
                new CancelOrderCommand(
                    $fixture['order_id'],
                    'c1000000-0000-4000-8000-000000000011',
                    'Saldos positivos mixtos',
                ),
            );
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(InventoryDomainException::class, $caught);
        $this->assertSame($before, $this->orderStateSnapshot());
        $this->assertSame(0, DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('tipo', PaymentType::Refund->value)
            ->count());
    }

    public function test_cancel_refunds_the_only_positive_net_payment_when_other_history_is_fully_refunded(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCancel], 'single_net_refund_actor');
        $fixture = $this->createCancellableOrder($actor, OrderStatus::Pending, false);
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000012',
            '3.00',
            PaymentType::Charge,
        );
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000013',
            '3.00',
            PaymentType::Refund,
        );
        $this->createOrderPaymentRecord(
            $fixture['order_id'],
            $actor,
            $fixture['payment_method_id'],
            $fixture['cash_session_id'],
            'c3000000-0000-4000-8000-000000000014',
            '2.00',
            PaymentType::Charge,
        );
        $cancelUuid = 'c1000000-0000-4000-8000-000000000012';

        $this->orderService()->cancel(
            $actor,
            new CancelOrderCommand($fixture['order_id'], $cancelUuid, 'Devolucion del saldo neto'),
        );

        $refunds = DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['order_id'])
            ->where('tipo', PaymentType::Refund->value)
            ->orderBy('id')
            ->get(['monto', 'clave_idempotencia']);
        $this->assertSame(['3.00', '2.00'], $refunds->pluck('monto')->all());
        $this->assertSame($cancelUuid, $refunds->last()->clave_idempotencia);
        $this->assertSame(1, $refunds->where('clave_idempotencia', $cancelUuid)->count());
    }

    private function createCancellableOrder(
        \App\Models\User $actor,
        OrderStatus $state,
        bool $withPayment = true,
    ): array {
        $clientId = $this->createOrderClient('cancel_client');
        $articleId = $this->createOrderArticle('cancel_article', '10.000', '10.00');
        $paymentMethodId = $this->createOrderPaymentMethod('cancel_payment');
        $cashSessionId = $this->createOrderCashSession($actor, 'cancel_cash');
        $orderId = $this->createHistoricalOrder(
            $actor,
            $clientId,
            $state,
            'c4000000-0000-4000-8000-'.str_pad((string) ($this->orderFixtureSequence + 1), 12, '0', STR_PAD_LEFT),
        );
        $article = DB::table('articulos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->where('articulos.id', $articleId)
            ->first([
                'articulos.sku',
                'articulos.nombre',
                'unidades_medida.codigo',
                'unidades_medida.simbolo',
            ]);
        DB::table('detalle_pedidos')->insert([
            'pedido_id' => $orderId,
            'articulo_id' => $articleId,
            'cantidad' => '1.000',
            'precio_unitario' => '10.00',
            'observacion' => null,
            'articulo_sku_snapshot' => $article->sku,
            'articulo_nombre_snapshot' => $article->nombre,
            'unidad_codigo_snapshot' => $article->codigo,
            'unidad_simbolo_snapshot' => $article->simbolo,
            'created_at' => '2026-09-24 12:00:00-03',
            'updated_at' => '2026-09-24 12:00:00-03',
        ]);

        if ($withPayment) {
            $this->createOrderPaymentRecord(
                $orderId,
                $actor,
                $paymentMethodId,
                $cashSessionId,
                'c5000000-0000-4000-8000-000000000001',
            );
        }

        return [
            'order_id' => $orderId,
            'article_id' => $articleId,
            'payment_method_id' => $paymentMethodId,
            'cash_session_id' => $cashSessionId,
        ];
    }

    private function orderService(): object
    {
        return app(OrderService::class);
    }
}
