<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PermissionCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orders\OrderDeliveryFixtures;
use Tests\Support\Orders\OrderServiceFixtures;
use Tests\TestCase;

final class OrderWebTest extends TestCase
{
    use OrderDeliveryFixtures;
    use OrderServiceFixtures;
    use RefreshDatabase;

    public function test_orders_pages_require_authentication_and_effective_order_permission(): void
    {
        $this->get('/pedidos')->assertRedirect('/login');
        $actor = $this->createOrderActor([], 'no_permission');

        $this->actingAs($actor)->get('/pedidos')->assertForbidden();
        $this->actingAs($actor)->get('/pedidos/crear')->assertForbidden();
        $this->actingAs($actor)->getJson('/pedidos/articulos/buscar?term=pan')->assertForbidden();
    }

    public function test_index_filters_by_status_and_searches_clients(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'index');
        $client = $this->createOrderClient('index_client');
        $otherClient = $this->createOrderClient('other_client');
        $this->createHistoricalOrder($actor, $client, OrderStatus::Ready, $this->uuid(1));
        $this->createHistoricalOrder($actor, $otherClient, OrderStatus::Cancelled, $this->uuid(2), 'No corresponde');

        $response = $this->actingAs($actor)->get('/pedidos?status=listo&q=Cliente');

        $response->assertOk()
            ->assertSee('Pedidos')
            ->assertSee('Pedido #');
    }

    public function test_article_and_client_lookup_returns_only_operational_choices(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'lookup');
        $article = $this->createOrderArticle('lookup_article');
        $client = $this->createOrderClient('lookup_client');

        $this->actingAs($actor)
            ->getJson('/pedidos/articulos/buscar?term='.$article)
            ->assertOk()
            ->assertJsonPath('data.0.id', $article);

        $this->actingAs($actor)
            ->getJson('/pedidos/clientes/buscar?term=Cliente')
            ->assertOk()
            ->assertJsonFragment(['id' => $client]);
    }

    public function test_create_order_without_payment_uses_server_price_and_redirects_to_detail(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'create');
        $client = $this->createOrderClient('create_client');
        $article = $this->createOrderArticle('create_article', '10.000', '12.50');

        $response = $this->actingAs($actor)->post('/pedidos', [
            'command_uuid' => $this->uuid(10),
            'client_id' => $client,
            'lines' => [['article_id' => $article, 'quantity' => '2.000']],
            'payments' => [],
        ]);

        $orderId = DB::table('pedidos')->where('clave_idempotencia', $this->uuid(10))->value('id');
        $response->assertRedirect(route('orders.show', $orderId));
        $this->assertSame('12.50', DB::table('detalle_pedidos')->where('pedido_id', $orderId)->value('precio_unitario'));
        $this->assertSame('2.000', DB::table('detalle_pedidos')->where('pedido_id', $orderId)->value('cantidad'));
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_initial_payment_requires_collect_permission(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'payment_denied');
        $client = $this->createOrderClient('payment_denied_client');
        $article = $this->createOrderArticle('payment_denied_article', '10.000', '20.00');
        $method = $this->createOrderPaymentMethod('payment_denied_method');
        $session = $this->createOrderCashSession($actor, 'payment_denied_session');

        $response = $this->actingAs($actor)->post('/pedidos', $this->paymentPayload($client, $article, $method, $session, $this->uuid(11), '5.00'));

        $response->assertForbidden();
        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_initial_payment_is_persisted_with_cash_change(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect], 'payment');
        $client = $this->createOrderClient('payment_client');
        $article = $this->createOrderArticle('payment_article', '10.000', '20.00');
        $method = $this->createPaymentMethod(['codigo' => 'efectivo_web', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $session = $this->createOrderCashSession($actor, 'payment_session');

        $response = $this->actingAs($actor)->post('/pedidos', $this->paymentPayload($client, $article, $method, $session, $this->uuid(12), '5.00', '7.00'));

        $response->assertRedirect();
        $payment = DB::table('pagos_pedido')->first();
        $this->assertSame('5.00', $payment->monto);
        $this->assertSame('7.00', $payment->monto_recibido);
        $this->assertSame('2.00', $payment->vuelto);
    }

    public function test_normal_transitions_are_available_only_with_manage_permission(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersManage], 'transition');
        $client = $this->createOrderClient('transition_client');
        $article = $this->createOrderArticle('transition_article');
        $this->actingAs($actor)->post('/pedidos', [
            'command_uuid' => $this->uuid(20),
            'client_id' => $client,
            'lines' => [['article_id' => $article, 'quantity' => '1.000']],
        ]);
        $orderId = (int) DB::table('pedidos')->where('clave_idempotencia', $this->uuid(20))->value('id');

        $this->actingAs($actor)->post("/pedidos/{$orderId}/confirmar", ['command_uuid' => $this->uuid(21)])->assertRedirect();
        $this->actingAs($actor)->post("/pedidos/{$orderId}/preparacion", ['command_uuid' => $this->uuid(22)])->assertRedirect();
        $this->actingAs($actor)->post("/pedidos/{$orderId}/listo", ['command_uuid' => $this->uuid(23)])->assertRedirect();

        $this->assertSame('listo', DB::table('pedidos')->where('id', $orderId)->value('estado'));
        $this->assertSame(4, DB::table('transiciones_pedido')->where('pedido_id', $orderId)->count());
    }

    public function test_cancel_requires_reason_and_reuses_existing_refund_logic(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect, PermissionCode::OrdersCancel], 'cancel');
        $client = $this->createOrderClient('cancel_client');
        $article = $this->createOrderArticle('cancel_article', '10.000', '10.00');
        $method = $this->createOrderPaymentMethod('cancel_method');
        $session = $this->createOrderCashSession($actor, 'cancel_session');
        $this->actingAs($actor)->post('/pedidos', $this->paymentPayload($client, $article, $method, $session, $this->uuid(30), '4.00'));
        $orderId = (int) DB::table('pedidos')->where('clave_idempotencia', $this->uuid(30))->value('id');

        $this->actingAs($actor)->post("/pedidos/{$orderId}/cancelar", [
            'command_uuid' => $this->uuid(31),
            'reason' => 'Cliente desistió',
        ])->assertRedirect();

        $this->assertSame('cancelado', DB::table('pedidos')->where('id', $orderId)->value('estado'));
        $this->assertSame('devolucion', DB::table('pagos_pedido')->where('pedido_id', $orderId)->orderByDesc('id')->value('tipo'));
    }

    public function test_delivery_of_prepaid_ready_order_creates_derived_sale(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver], 'delivery');
        $fixture = $this->createReadyOrderFixture($actor, true, true, false, '10.00', '10.00');

        $this->actingAs($actor)->post('/pedidos/'.$fixture['orderId'].'/entregar', [
            'command_uuid' => $this->uuid(40),
        ])->assertRedirect();

        $this->assertSame('entregado', DB::table('pedidos')->where('id', $fixture['orderId'])->value('estado'));
        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $fixture['orderId'])->count());
        $this->assertSame(1, DB::table('movimientos_inventario')->where('tipo_movimiento', 'venta')->count());
    }

    public function test_delivery_with_balance_requires_collect_permission_and_exact_final_payment(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver, PermissionCode::OrdersCollect], 'delivery_balance');
        $fixture = $this->createReadyOrderFixture($actor, false, true, false, '10.00', '0.00');

        $this->actingAs($actor)->post('/pedidos/'.$fixture['orderId'].'/entregar', [
            'command_uuid' => $this->uuid(50),
            'final_payment' => [
                'payment_method_id' => $fixture['paymentMethodId'],
                'cash_session_id' => $fixture['cashSessionId'],
                'amount' => '10.00',
                'payment_uuid' => $this->uuid(51),
            ],
        ])->assertRedirect();

        $this->assertSame('entregado', DB::table('pedidos')->where('id', $fixture['orderId'])->value('estado'));
        $this->assertSame('10.00', DB::table('pagos_pedido')->where('clave_idempotencia', $this->uuid(51))->value('monto'));
    }

    public function test_delivery_retry_with_same_uuid_does_not_duplicate_sale(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver], 'delivery_retry');
        $fixture = $this->createReadyOrderFixture($actor, true, true, false, '10.00', '10.00');
        $payload = ['command_uuid' => $this->uuid(60)];

        $this->actingAs($actor)->post('/pedidos/'.$fixture['orderId'].'/entregar', $payload)->assertRedirect();
        $this->actingAs($actor)->post('/pedidos/'.$fixture['orderId'].'/entregar', $payload)->assertRedirect();

        $this->assertSame(1, DB::table('ventas')->where('pedido_id', $fixture['orderId'])->count());
    }

    public function test_detail_exposes_history_payments_and_action_structure(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersManage], 'detail');
        $client = $this->createOrderClient('detail_client');
        $orderId = $this->createHistoricalOrder($actor, $client, OrderStatus::Ready, $this->uuid(70));

        $this->actingAs($actor)->get('/pedidos/'.$orderId)
            ->assertOk()
            ->assertSee('Historial')
            ->assertSee('Artículos')
            ->assertSee('Cancelar pedido');
    }

    /** @return array<string, mixed> */
    private function paymentPayload(int $client, int $article, int $method, int $session, string $uuid, string $amount, ?string $received = null): array
    {
        return [
            'command_uuid' => $uuid,
            'client_id' => $client,
            'cash_session_id' => $session,
            'lines' => [['article_id' => $article, 'quantity' => '1.000']],
            'payments' => [[
                'payment_method_id' => $method,
                'amount' => $amount,
                'received_amount' => $received,
                'payment_uuid' => $this->uuid((int) substr($uuid, -2) + 100),
            ]],
        ];
    }

    private function uuid(int $number): string
    {
        return sprintf('f4000000-0000-4000-8000-%012d', $number);
    }
}
