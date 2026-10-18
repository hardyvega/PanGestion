<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Orders;

use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\OrderLineData;
use App\Data\Orders\OrderPaymentData;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Pedido;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orders\OrderServiceFixtures;
use Tests\TestCase;

final class OrderServiceCreationTest extends TestCase
{
    use OrderServiceFixtures;
    use RefreshDatabase;

    public function test_create_persists_pending_order_details_snapshots_initial_transition_and_no_operational_effects(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate]);
        $clientId = $this->createOrderClient();
        $firstArticleId = $this->createOrderArticle('primero', '10.000', '12.50');
        $secondArticleId = $this->createOrderArticle('segundo', '8.000', '8.00');
        $stockBefore = DB::table('articulos')
            ->whereIn('id', [$firstArticleId, $secondArticleId])
            ->orderBy('id')
            ->pluck('stock_actual', 'id')
            ->all();
        $salesBefore = DB::table('ventas')->count();
        $movementsBefore = DB::table('movimientos_inventario')->count();
        $dailyStockBefore = DB::table('stock_diario')->count();
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000001',
            $clientId,
            [
                new OrderLineData($firstArticleId, '2.000', '12.50', 'Primera linea'),
                new OrderLineData($firstArticleId, '1.250', '12.50', 'Segunda linea'),
                new OrderLineData($secondArticleId, '1.000', '8.00'),
            ],
            [],
            CarbonImmutable::parse('2026-10-01 10:00:00-03'),
            'Retirar en mostrador',
        );

        $order = $this->orderService()->create($actor, $command);

        $this->assertInstanceOf(Pedido::class, $order);
        $this->assertSame(OrderStatus::Pending, $order->estado);
        $this->assertSame($clientId, $order->cliente_id);
        $this->assertSame((int) $actor->getKey(), $order->usuario_creador_id);
        $this->assertSame($command->commandUuid, $order->clave_idempotencia);
        $this->assertSame('Retirar en mostrador', $order->observacion);
        $this->assertSame(3, DB::table('detalle_pedidos')->where('pedido_id', $order->getKey())->count());

        $details = DB::table('detalle_pedidos')
            ->where('pedido_id', $order->getKey())
            ->orderBy('id')
            ->get();
        $this->assertSame([$firstArticleId, $firstArticleId, $secondArticleId], $details->pluck('articulo_id')->all());
        $this->assertSame(['2.000', '1.250', '1.000'], $details->pluck('cantidad')->all());
        $this->assertSame(['12.50', '12.50', '8.00'], $details->pluck('precio_unitario')->all());
        $catalog = DB::table('articulos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->whereIn('articulos.id', [$firstArticleId, $secondArticleId])
            ->get([
                'articulos.id',
                'articulos.sku',
                'articulos.nombre',
                'unidades_medida.codigo',
                'unidades_medida.simbolo',
            ])
            ->keyBy('id');
        $this->assertSame([
            $catalog[$firstArticleId]->sku,
            $catalog[$firstArticleId]->sku,
            $catalog[$secondArticleId]->sku,
        ], $details->pluck('articulo_sku_snapshot')->all());
        $this->assertSame([
            $catalog[$firstArticleId]->nombre,
            $catalog[$firstArticleId]->nombre,
            $catalog[$secondArticleId]->nombre,
        ], $details->pluck('articulo_nombre_snapshot')->all());
        $this->assertSame([
            $catalog[$firstArticleId]->codigo,
            $catalog[$firstArticleId]->codigo,
            $catalog[$secondArticleId]->codigo,
        ], $details->pluck('unidad_codigo_snapshot')->all());
        $this->assertSame([
            $catalog[$firstArticleId]->simbolo,
            $catalog[$firstArticleId]->simbolo,
            $catalog[$secondArticleId]->simbolo,
        ], $details->pluck('unidad_simbolo_snapshot')->all());

        $transition = DB::table('transiciones_pedido')->where('pedido_id', $order->getKey())->first();
        $this->assertNotNull($transition);
        $this->assertNull($transition->estado_anterior);
        $this->assertSame(OrderStatus::Pending->value, $transition->estado_nuevo);
        $this->assertSame((int) $actor->getKey(), $transition->usuario_id);
        $this->assertSame($command->commandUuid, $transition->clave_idempotencia);
        $this->assertNull($transition->motivo);

        $this->assertSame($stockBefore, DB::table('articulos')
            ->whereIn('id', [$firstArticleId, $secondArticleId])
            ->orderBy('id')
            ->pluck('stock_actual', 'id')
            ->all());
        $this->assertSame($salesBefore, DB::table('ventas')->count());
        $this->assertSame($movementsBefore, DB::table('movimientos_inventario')->count());
        $this->assertSame($dailyStockBefore, DB::table('stock_diario')->count());
    }

    public function test_create_persists_a_partial_initial_payment_with_both_permissions(): void
    {
        $actor = $this->createOrderActor([
            PermissionCode::OrdersCreate,
            PermissionCode::OrdersCollect,
        ], 'payment_actor');
        $clientId = $this->createOrderClient('payment_client');
        $articleId = $this->createOrderArticle('payment_article', '10.000', '20.00');
        $paymentMethodId = $this->createOrderPaymentMethod();
        $cashSessionId = $this->createOrderCashSession($actor);
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000002',
            $clientId,
            [new OrderLineData($articleId, '1.000', '20.00')],
            [new OrderPaymentData(
                $paymentMethodId,
                PaymentType::Charge,
                '5.00',
                $cashSessionId,
                'a1000000-0000-4000-8000-000000000003',
                '7.00',
                '2.00',
                'Abono inicial',
            )],
        );

        $order = $this->orderService()->create($actor, $command);
        $payment = DB::table('pagos_pedido')->where('pedido_id', $order->getKey())->first();

        $this->assertNotNull($payment);
        $this->assertSame($paymentMethodId, $payment->metodo_pago_id);
        $this->assertSame($cashSessionId, $payment->sesion_caja_id);
        $this->assertSame((int) $actor->getKey(), $payment->usuario_id);
        $this->assertSame('a1000000-0000-4000-8000-000000000003', $payment->clave_idempotencia);
        $this->assertSame(PaymentType::Charge->value, $payment->tipo);
        $this->assertSame('5.00', $payment->monto);
        $this->assertSame('7.00', $payment->monto_recibido);
        $this->assertSame('2.00', $payment->vuelto);
        $this->assertSame('Abono inicial', $payment->observacion);
    }

    public function test_create_with_initial_payment_requires_orders_collect_permission_and_rolls_back(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'payment_without_collect_actor');
        $clientId = $this->createOrderClient('payment_without_collect_client');
        $articleId = $this->createOrderArticle('payment_without_collect_article', '10.000', '10.00');
        $paymentMethodId = $this->createOrderPaymentMethod('payment_without_collect');
        $cashSessionId = $this->createOrderCashSession($actor, 'payment_without_collect');
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000008',
            $clientId,
            [new OrderLineData($articleId, '1.000', '10.00')],
            [new OrderPaymentData(
                $paymentMethodId,
                PaymentType::Charge,
                '4.00',
                $cashSessionId,
                'a1000000-0000-4000-8000-000000000009',
            )],
        );
        $before = $this->orderStateSnapshot();

        $caught = null;
        try {
            $this->orderService()->create($actor, $command);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(AuthorizationException::class, $caught);
        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_create_rejects_initial_overpayment_and_rolls_back_without_operational_effects(): void
    {
        $actor = $this->createOrderActor([
            PermissionCode::OrdersCreate,
            PermissionCode::OrdersCollect,
        ], 'overpayment_actor');
        $clientId = $this->createOrderClient('overpayment_client');
        $articleId = $this->createOrderArticle('overpayment_article', '10.000', '10.00');
        $paymentMethodId = $this->createOrderPaymentMethod('overpayment');
        $cashSessionId = $this->createOrderCashSession($actor, 'overpayment');
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000010',
            $clientId,
            [new OrderLineData($articleId, '1.000', '10.00')],
            [new OrderPaymentData(
                $paymentMethodId,
                PaymentType::Charge,
                '11.00',
                $cashSessionId,
                'a1000000-0000-4000-8000-000000000011',
            )],
        );
        $before = $this->orderStateSnapshot();

        $caught = null;
        try {
            $this->orderService()->create($actor, $command);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(InventoryDomainException::class, $caught);
        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_create_rejects_a_missing_client_without_creating_one(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'missing_client_actor');
        $articleId = $this->createOrderArticle('missing_client_article');
        $clientsBefore = DB::table('clientes')->count();
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000004',
            999999,
            [new OrderLineData($articleId, '1.000', '12.50')],
        );

        $this->expectException(ModelNotFoundException::class);
        $this->orderService()->create($actor, $command);

        $this->assertSame($clientsBefore, DB::table('clientes')->count());
        $this->assertSame(0, DB::table('pedidos')->count());
        $this->assertSame(0, DB::table('detalle_pedidos')->count());
        $this->assertSame(0, DB::table('transiciones_pedido')->count());
    }

    public function test_create_requires_orders_create_permission_and_rolls_back_state(): void
    {
        $actor = $this->createOrderActor([], 'unauthorized_create_actor');
        $clientId = $this->createOrderClient('unauthorized_create_client');
        $articleId = $this->createOrderArticle('unauthorized_create_article');
        $before = $this->orderStateSnapshot();
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000005',
            $clientId,
            [new OrderLineData($articleId, '1.000', '12.50')],
        );

        $this->expectException(AuthorizationException::class);
        $this->orderService()->create($actor, $command);

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_create_retry_with_same_uuid_and_same_intention_is_idempotent(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'create_retry_actor');
        $clientId = $this->createOrderClient('create_retry_client');
        $articleId = $this->createOrderArticle('create_retry_article');
        $command = new CreateOrderCommand(
            'a1000000-0000-4000-8000-000000000006',
            $clientId,
            [new OrderLineData($articleId, '1.000', '12.50')],
        );

        $first = $this->orderService()->create($actor, $command);
        $second = $this->orderService()->create($actor, $command);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, DB::table('pedidos')->where('clave_idempotencia', $command->commandUuid)->count());
        $this->assertSame(1, DB::table('detalle_pedidos')->where('pedido_id', $first->getKey())->count());
        $this->assertSame(0, DB::table('pagos_pedido')->where('pedido_id', $first->getKey())->count());
        $this->assertSame(1, DB::table('transiciones_pedido')->where('pedido_id', $first->getKey())->count());
    }

    public function test_create_retry_with_same_uuid_and_different_intention_conflicts_and_rolls_back(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'create_conflict_actor');
        $clientId = $this->createOrderClient('create_conflict_client');
        $articleId = $this->createOrderArticle('create_conflict_article');
        $uuid = 'a1000000-0000-4000-8000-000000000007';
        $first = new CreateOrderCommand(
            $uuid,
            $clientId,
            [new OrderLineData($articleId, '1.000', '12.50')],
        );
        $different = new CreateOrderCommand(
            $uuid,
            $clientId,
            [new OrderLineData($articleId, '2.000', '12.50')],
        );

        $order = $this->orderService()->create($actor, $first);
        $before = $this->orderStateSnapshot();

        $this->expectException(IdempotencyConflictException::class);
        $this->orderService()->create($actor, $different);

        $this->assertSame($before, $this->orderStateSnapshot());
        $this->assertSame(1, DB::table('pedidos')->where('id', $order->getKey())->count());
    }

    private function orderService(): object
    {
        return app(OrderService::class);
    }
}
