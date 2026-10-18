<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Orders;

use App\Data\Orders\TransitionOrderCommand;
use App\Enums\OrderStatus;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Orders\OrderServiceFixtures;
use Tests\TestCase;

final class OrderServiceTransitionTest extends TestCase
{
    use OrderServiceFixtures;
    use RefreshDatabase;

    public function test_normal_workflow_is_append_only_and_does_not_touch_inventory_or_sales(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'workflow_actor');
        $orderId = $this->createOrderWithDetail($actor, OrderStatus::Pending, 'b1000000-0000-4000-8000-000000000001');
        $before = $this->orderStateSnapshot();
        $commands = [
            [OrderStatus::Confirmed, 'b1000000-0000-4000-8000-000000000002'],
            [OrderStatus::InPreparation, 'b1000000-0000-4000-8000-000000000003'],
            [OrderStatus::Ready, 'b1000000-0000-4000-8000-000000000004'],
        ];

        foreach ($commands as [$target, $uuid]) {
            $order = $this->orderService()->transition(
                $actor,
                new TransitionOrderCommand($orderId, $uuid, $target),
            );

            $this->assertSame($target, $order->estado);
        }

        $history = DB::table('transiciones_pedido')
            ->where('pedido_id', $orderId)
            ->orderBy('id')
            ->get();
        $this->assertSame(4, $history->count());
        $this->assertSame([
            [null, 'pendiente', 'b1000000-0000-4000-8000-000000000001'],
            ['pendiente', 'confirmado', 'b1000000-0000-4000-8000-000000000002'],
            ['confirmado', 'en_preparacion', 'b1000000-0000-4000-8000-000000000003'],
            ['en_preparacion', 'listo', 'b1000000-0000-4000-8000-000000000004'],
        ], $history->map(static fn (object $row): array => [
            $row->estado_anterior,
            $row->estado_nuevo,
            $row->clave_idempotencia,
        ])->all());
        $this->assertSame($before['transiciones_pedido'], array_slice($this->orderStateSnapshot()['transiciones_pedido'], 0, 1));
        $after = $this->orderStateSnapshot();
        $this->assertSame($before['articulos'], $after['articulos']);
        $this->assertSame($before['movimientos_inventario'], $after['movimientos_inventario']);
        $this->assertSame($before['stock_diario'], $after['stock_diario']);
        $this->assertSame($before['ventas'], $after['ventas']);
        $this->assertSame($before['detalle_ventas'], $after['detalle_ventas']);
        $this->assertSame($before['pagos_venta'], $after['pagos_venta']);
    }

    public function test_transition_retry_with_same_uuid_and_same_intention_is_idempotent(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'transition_retry_actor');
        $orderId = $this->createOrderWithDetail($actor, OrderStatus::Pending, 'b1000000-0000-4000-8000-000000000005');
        $command = new TransitionOrderCommand(
            $orderId,
            'b1000000-0000-4000-8000-000000000006',
            OrderStatus::Confirmed,
        );

        $first = $this->orderService()->transition($actor, $command);
        $second = $this->orderService()->transition($actor, $command);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(OrderStatus::Confirmed, $second->estado);
        $this->assertSame(2, DB::table('transiciones_pedido')->where('pedido_id', $orderId)->count());
    }

    public function test_transition_retry_with_same_uuid_and_different_intention_conflicts(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'transition_conflict_actor');
        $orderId = $this->createOrderWithDetail($actor, OrderStatus::Pending, 'b1000000-0000-4000-8000-000000000007');
        $uuid = 'b1000000-0000-4000-8000-000000000008';

        $this->orderService()->transition(
            $actor,
            new TransitionOrderCommand($orderId, $uuid, OrderStatus::Confirmed),
        );
        $before = $this->orderStateSnapshot();

        $this->expectException(IdempotencyConflictException::class);
        $this->orderService()->transition(
            $actor,
            new TransitionOrderCommand($orderId, $uuid, OrderStatus::InPreparation),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    #[DataProvider('invalidTransitionCases')]
    public function test_invalid_transition_targets_are_rejected(
        OrderStatus $current,
        OrderStatus $target,
        ?string $reason = null,
    ): void {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'invalid_transition_actor');
        $orderId = $this->createOrderWithDetail(
            $actor,
            $current,
            'b1000000-0000-4000-8000-000000000009',
        );
        $before = $this->orderStateSnapshot();

        $this->expectException(InventoryDomainException::class);
        $this->orderService()->transition(
            $actor,
            new TransitionOrderCommand(
                $orderId,
                $this->orderFixtureUuid('000000000010'),
                $target,
                $reason,
            ),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public static function invalidTransitionCases(): array
    {
        return [
            'pending to preparation' => [OrderStatus::Pending, OrderStatus::InPreparation],
            'pending to ready' => [OrderStatus::Pending, OrderStatus::Ready],
            'confirmed to ready' => [OrderStatus::Confirmed, OrderStatus::Ready],
            'confirmed backwards' => [OrderStatus::Confirmed, OrderStatus::Pending],
            'preparation backwards' => [OrderStatus::InPreparation, OrderStatus::Confirmed],
            'ready backwards' => [OrderStatus::Ready, OrderStatus::InPreparation],
            'transition cannot deliver' => [OrderStatus::Pending, OrderStatus::Delivered],
            'transition cannot cancel' => [OrderStatus::Pending, OrderStatus::Cancelled, 'Cancelacion separada'],
        ];
    }

    public function test_transition_requires_orders_manage_permission_and_preserves_history(): void
    {
        $actor = $this->createOrderActor([], 'unauthorized_transition_actor');
        $orderId = $this->createOrderWithDetail($actor, OrderStatus::Pending, 'b1000000-0000-4000-8000-000000000011');
        $before = $this->orderStateSnapshot();

        $this->expectException(AuthorizationException::class);
        $this->orderService()->transition(
            $actor,
            new TransitionOrderCommand(
                $orderId,
                'b1000000-0000-4000-8000-000000000012',
                OrderStatus::Confirmed,
            ),
        );

        $this->assertSame($before, $this->orderStateSnapshot());
    }

    public function test_repeating_a_reached_target_with_a_new_uuid_is_rejected_without_duplicate_history(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'reached_target_actor');
        $orderId = $this->createOrderWithDetail($actor, OrderStatus::Pending, 'b1000000-0000-4000-8000-000000000013');
        $this->orderService()->transition(
            $actor,
            new TransitionOrderCommand(
                $orderId,
                'b1000000-0000-4000-8000-000000000014',
                OrderStatus::Confirmed,
            ),
        );
        $historyBefore = DB::table('transiciones_pedido')->where('pedido_id', $orderId)->count();

        $this->expectException(InventoryDomainException::class);
        $this->orderService()->transition(
            $actor,
            new TransitionOrderCommand(
                $orderId,
                'b1000000-0000-4000-8000-000000000015',
                OrderStatus::Confirmed,
            ),
        );

        $this->assertSame($historyBefore, DB::table('transiciones_pedido')->where('pedido_id', $orderId)->count());
    }

    private function createOrderWithDetail(User $actor, OrderStatus $status, string $uuid): int
    {
        $clientId = $this->createOrderClient('transition_client');
        $articleId = $this->createOrderArticle('transition_article');
        $orderId = $this->createHistoricalOrder($actor, $clientId, $status, $uuid);
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
            'precio_unitario' => '12.50',
            'observacion' => null,
            'articulo_sku_snapshot' => $article->sku,
            'articulo_nombre_snapshot' => $article->nombre,
            'unidad_codigo_snapshot' => $article->codigo,
            'unidad_simbolo_snapshot' => $article->simbolo,
            'created_at' => '2026-09-24 12:00:00-03',
            'updated_at' => '2026-09-24 12:00:00-03',
        ]);

        return $orderId;
    }

    private function orderService(): object
    {
        return app(OrderService::class);
    }
}
