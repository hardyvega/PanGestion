<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Orders;

use App\Data\Orders\DeliverOrderCommand;
use App\Data\Orders\OrderPaymentData;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Pedido;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orders\OrderDeliveryFixtures;
use Tests\TestCase;

final class OrderServiceDeliveryTest extends TestCase
{
    use RefreshDatabase;
    use OrderDeliveryFixtures;

    public function test_delivery_of_fully_prepaid_ready_order_creates_derived_sale_and_inventory_causality(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver]);
        $fixture = $this->createReadyOrderFixture($actor);

        $order = $this->service()->deliver(
            $actor,
            new DeliverOrderCommand($fixture['orderId'], $this->deliveryUuid(10)),
        );

        $this->assertInstanceOf(Pedido::class, $order);
        $saleId = DB::table('ventas')->where('pedido_id', $fixture['orderId'])->value('id');
        $this->assertSame('entregado', DB::table('pedidos')->where('id', $fixture['orderId'])->value('estado'));
        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $fixture['orderId'])->count());
        $this->assertSame('10.00', DB::table('ventas')->where('id', $saleId)->value('monto_anticipo_aplicado'));
        $this->assertSame(0, DB::table('pagos_venta')->where('venta_id', $saleId)->count());
        $this->assertDatabaseHas('transiciones_pedido', [
            'pedido_id' => $fixture['orderId'],
            'estado_nuevo' => 'entregado',
            'clave_idempotencia' => $this->deliveryUuid(10),
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'tipo_movimiento' => 'venta',
            'clave_idempotencia' => $this->deliveryUuid(10),
            'detalle_venta_id' => DB::table('detalle_ventas')->where('venta_id', $saleId)->value('id'),
        ]);
    }

    public function test_delivery_with_final_cash_payment_records_payment_and_derived_cash_causality(): void
    {
        $actor = $this->createDeliveryActor([
            PermissionCode::OrdersDeliver,
            PermissionCode::OrdersCollect,
        ]);
        $fixture = $this->createReadyOrderFixture(
            $actor,
            prepaid: true,
            cashPayment: true,
            total: '10.00',
            priorPayment: '4.00',
        );
        $paymentUuid = 'd8500000-0000-4000-8000-000000000001';

        $order = $this->service()->deliver(
            $actor,
            new DeliverOrderCommand(
                $fixture['orderId'],
                $this->deliveryUuid(11),
                new OrderPaymentData(
                    $fixture['paymentMethodId'],
                    PaymentType::Charge,
                    '6.00',
                    $fixture['cashSessionId'],
                    $paymentUuid,
                ),
            ),
        );

        $paymentId = DB::table('pagos_pedido')->where('clave_idempotencia', $paymentUuid)->value('id');
        $saleId = DB::table('ventas')->where('pedido_id', $fixture['orderId'])->value('id');
        $this->assertNotNull($paymentId);
        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_pedido_id', $paymentId)->count());
        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $fixture['orderId'])->count());
        $this->assertSame('10.00', DB::table('ventas')->where('id', $saleId)->value('total'));
        $this->assertSame('4.00', DB::table('ventas')->where('id', $saleId)->value('monto_anticipo_aplicado'));
        $this->assertSame('entregado', $order->estado->value);
    }

    public function test_final_payment_requires_orders_collect_permission_without_mutation(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver]);
        $fixture = $this->createReadyOrderFixture(
            $actor,
            prepaid: true,
            cashPayment: true,
            total: '10.00',
            priorPayment: '4.00',
        );
        $before = $this->deliveryState($fixture['orderId']);

        $this->expectException(AuthorizationException::class);
        try {
            $this->service()->deliver(
                $actor,
                new DeliverOrderCommand(
                    $fixture['orderId'],
                    $this->deliveryUuid(15),
                    new OrderPaymentData(
                        $fixture['paymentMethodId'],
                        PaymentType::Charge,
                        '6.00',
                        $fixture['cashSessionId'],
                        'd8500000-0000-4000-8000-000000000002',
                    ),
                ),
            );
        } finally {
            $this->assertSame($before, $this->deliveryState($fixture['orderId']));
        }
    }

    public function test_final_payment_does_not_replace_orders_deliver_permission(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersCollect]);
        $fixture = $this->createReadyOrderFixture(
            $actor,
            prepaid: true,
            cashPayment: true,
            total: '10.00',
            priorPayment: '4.00',
        );
        $before = $this->deliveryState($fixture['orderId']);

        $this->expectException(AuthorizationException::class);
        try {
            $this->service()->deliver(
                $actor,
                new DeliverOrderCommand(
                    $fixture['orderId'],
                    $this->deliveryUuid(16),
                    new OrderPaymentData(
                        $fixture['paymentMethodId'],
                        PaymentType::Charge,
                        '6.00',
                        $fixture['cashSessionId'],
                        'd8500000-0000-4000-8000-000000000003',
                    ),
                ),
            );
        } finally {
            $this->assertSame($before, $this->deliveryState($fixture['orderId']));
        }
    }

    public function test_delivery_with_only_final_payment_keeps_advance_at_zero(): void
    {
        $actor = $this->createDeliveryActor([
            PermissionCode::OrdersDeliver,
            PermissionCode::OrdersCollect,
        ]);
        $fixture = $this->createReadyOrderFixture(
            $actor,
            prepaid: false,
            cashPayment: true,
            total: '10.00',
        );

        $this->service()->deliver(
            $actor,
            new DeliverOrderCommand(
                $fixture['orderId'],
                $this->deliveryUuid(17),
                new OrderPaymentData(
                    $fixture['paymentMethodId'],
                    PaymentType::Charge,
                    '10.00',
                    $fixture['cashSessionId'],
                        'd8500000-0000-4000-8000-000000000004',
                ),
            ),
        );

        $saleId = DB::table('ventas')->where('pedido_id', $fixture['orderId'])->value('id');
        $this->assertSame('0.00', DB::table('ventas')->where('id', $saleId)->value('monto_anticipo_aplicado'));
    }

    public function test_delivery_rejects_a_non_ready_order_without_mutating_operational_state(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver]);
        $fixture = $this->createReadyOrderFixture($actor);
        DB::table('pedidos')->where('id', $fixture['orderId'])->update(['estado' => 'confirmado']);
        $before = $this->deliveryState($fixture['orderId']);

        $this->expectException(InventoryDomainException::class);
        try {
            $this->service()->deliver(
                $actor,
                new DeliverOrderCommand($fixture['orderId'], $this->deliveryUuid(12)),
            );
        } finally {
            $this->assertSame($before, $this->deliveryState($fixture['orderId']));
        }
    }

    public function test_delivery_rejects_legacy_details_without_snapshots(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver]);
        $fixture = $this->createReadyOrderFixture($actor, withSnapshots: false);

        $this->expectException(InventoryDomainException::class);
        $this->service()->deliver(
            $actor,
            new DeliverOrderCommand($fixture['orderId'], $this->deliveryUuid(13)),
        );
    }

    public function test_delivery_retry_with_same_uuid_is_idempotent_and_different_order_conflicts(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver]);
        $first = $this->createReadyOrderFixture($actor);
        $second = $this->createReadyOrderFixture($actor);
        $uuid = $this->deliveryUuid(14);

        $this->service()->deliver($actor, new DeliverOrderCommand($first['orderId'], $uuid));
        $before = DB::table('ventas')->count();
        $this->service()->deliver($actor, new DeliverOrderCommand($first['orderId'], $uuid));
        $this->assertSame($before, DB::table('ventas')->count());

        $this->expectException(\App\Exceptions\Inventory\IdempotencyConflictException::class);
        $this->service()->deliver($actor, new DeliverOrderCommand($second['orderId'], $uuid));
    }

    private function service(): object
    {
        return $this->app->make(\App\Services\Orders\OrderService::class);
    }
}
