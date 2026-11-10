<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PermissionCode;
use App\Models\Pedido;
use App\Services\Cash\CashService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
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

    public function test_create_free_order_without_payment_uses_manual_price_and_preserves_contact_privacy(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'create');
        $article = $this->createOrderArticle('create_article', '10.000', '12.50');

        $this->actingAs($actor)->get('/pedidos/crear')->assertOk()
            ->assertSee('Nombre del cliente')->assertSee('Teléfono de contacto')->assertSee('Fecha y hora de entrega')
            ->assertSee('Detalle del pedido')->assertSee('Precio total acordado')
            ->assertDontSee('SKU')->assertDontSee('pedidos.cobrar')->assertDontSee('idempotencia');
        $response = $this->post('/pedidos', [...$this->orderPayload($this->uuid(10), '$ 25.001'), 'specifications' => 'Sin nueces.']);

        $order = Pedido::query()->where('clave_idempotencia', $this->uuid(10))->firstOrFail();
        $orderId = $order->getKey();
        $response->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $orderId));
        $this->assertNull($order->cliente_id);
        $this->assertSame('Ana de prueba', $order->cliente_nombre);
        $this->assertSame('+56223456789', $order->cliente_telefono_cifrado);
        $this->assertNotSame('+56223456789', $order->getRawOriginal('cliente_telefono_cifrado'));
        $this->assertArrayNotHasKey('cliente_telefono_cifrado', $order->toArray());
        $this->assertSame('2026-11-20 19:30:00', $order->entrega_programada_en->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('clientes', 0);
        $this->assertDatabaseCount('pagos_pedido', 0);
        $this->assertDatabaseCount('movimientos_caja', 0);
        $this->assertTrue($order->isFree());
        $this->assertSame('25001.00', $order->precio_total_acordado);
        $this->assertSame('Sin nueces.', $order->especificaciones_texto);
        $this->assertDatabaseCount('detalle_pedidos', 0);
        $this->assertDatabaseHas('articulos', ['id' => $article, 'stock_actual' => '10.000', 'precio_venta_actual' => '12.50']);
        $this->assertSame(0, DB::table('movimientos_inventario')->count());

        $this->get(route('orders.show', $order))->assertOk()->assertSee('Sin pagar')
            ->assertSee('20/11/2026 16:30')->assertSee('Sin nueces.')->assertSee('$25.001')->assertDontSee('+56223456789');
        $manager = $this->createOrderActor([PermissionCode::OrdersManage], 'contact_manager');
        $this->actingAs($manager)->get(route('orders.show', $order))->assertOk()->assertSee('+56223456789');
        $this->get('/pedidos?q=kuchen')->assertOk()->assertSee('Ana de prueba')->assertSee('Dos kuchen de prueba')
            ->assertSee('Saldo $25.001')->assertDontSee('+56223456789')->assertDontSee('Sin nueces.');
        $this->get('/pedidos?q=56223456789')->assertOk()->assertDontSee('Ana de prueba');
        $deliverer = $this->createOrderActor([PermissionCode::OrdersDeliver], 'contact_delivery');
        $this->actingAs($deliverer)->get(route('orders.show', $order))->assertOk()->assertSee('+56223456789');
    }

    public function test_required_fields_and_invalid_contact_or_delivery_are_rejected(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'invalid_contact');
        $payload = $this->orderPayload($this->uuid(8));
        $this->actingAs($actor)->from('/pedidos/crear')->post('/pedidos', [
            ...$payload, 'contact_name' => ' ', 'contact_phone' => '', 'description' => '', 'agreed_total' => null, 'scheduled_delivery_at' => null,
        ])->assertSessionHasErrors(['contact_name', 'contact_phone', 'description', 'agreed_total', 'scheduled_delivery_at']);

        $this->post('/pedidos', [
            ...$payload, 'contact_phone' => '+56 02 letras', 'scheduled_delivery_at' => '2026-02-30T18:30',
        ])->assertSessionHasErrors(['contact_phone', 'scheduled_delivery_at'])
            ->assertSessionMissing('_old_input.contact_phone')->assertSessionMissing('_old_input.contact_name');
        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_create_retry_compares_normalized_contact_description_specifications_and_price(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'retry_contact');
        $payload = [...$this->orderPayload($this->uuid(9)), 'contact_phone' => '00 1 (202) 555-0123', 'description' => "Kuchen\r\nPie", 'specifications' => ' Sin nueces '];
        $this->actingAs($actor)->post('/pedidos', $payload)->assertSessionHasNoErrors();
        $this->post('/pedidos', [...$payload, 'contact_phone' => '+12025550123', 'description' => "Kuchen\nPie", 'agreed_total' => '$20'])->assertSessionHasNoErrors();
        foreach (['description' => 'Otro encargo', 'specifications' => 'Con nueces', 'agreed_total' => '21'] as $field => $value) {
            $this->post('/pedidos', [...$payload, $field => $value])->assertSessionHasErrors('order');
        }
        $this->post('/pedidos', [...$payload, 'contact_phone' => '+12025550124'])
            ->assertSessionHasErrors('order')->assertSessionMissing('_old_input.contact_phone');
        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_agreed_total_requires_positive_whole_clp_and_never_rounds_input(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'clp');
        $payload = $this->orderPayload($this->uuid(7));
        $this->actingAs($actor)->from('/pedidos/crear');
        foreach (['0', '-1', '25.00', '25.000,50', '1e3', '1.000.000.000.000', 50.25, ['25000']] as $invalid) {
            $this->postJson('/pedidos', [...$payload, 'agreed_total' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('agreed_total');
        }
        $this->post('/pedidos', [...$payload, 'specifications' => str_repeat('x', 2001), 'lines' => [['article_id' => 1]]])
            ->assertSessionHasErrors(['specifications', 'lines'])->assertSessionHas('_old_input.description', $payload['description']);
        $this->assertDatabaseCount('pedidos', 0);
        $this->post('/pedidos', [...$payload, 'agreed_total' => '999.999.999.999'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pedidos', ['precio_total_acordado' => '999999999999.00', 'especificaciones_texto' => null]);
    }

    public function test_database_rejects_mixing_catalog_lines_and_free_order_details(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'mode_guard');
        $article = $this->createOrderArticle('mode_guard');
        $this->actingAs($actor)->post('/pedidos', $this->orderPayload($this->uuid(6)))->assertSessionHasNoErrors();
        $order = Pedido::query()->firstOrFail();
        $mutations = [
            fn () => $this->createOrderDetail((int) $order->id, $article),
            fn () => DB::table('pedidos')->where('id', $order->id)->update(['precio_total_acordado' => '19.99']),
            fn () => DB::table('pedidos')->where('id', $order->id)->update(['modalidad' => 'catalogo']),
        ];
        foreach ($mutations as $mutation) {
            try {
                DB::transaction($mutation);
                $this->fail('Se aceptó un pedido con datos incompatibles.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $this->assertDatabaseCount('detalle_pedidos', 0);
        $this->assertDatabaseHas('pedidos', ['id' => $order->id, 'modalidad' => 'libre', 'precio_total_acordado' => '20.00']);
    }

    public function test_initial_payment_requires_collect_permission(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'payment_denied');
        $method = $this->createOrderPaymentMethod('payment_denied_method');
        $session = $this->createOrderCashSession($actor, 'payment_denied_session');

        $response = $this->actingAs($actor)->post('/pedidos', $this->paymentPayload($method, $session, $this->uuid(11), '5.00'));

        $response->assertForbidden();
        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_initial_payment_is_persisted_with_cash_change(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect], 'payment');
        $this->travelTo(CarbonImmutable::parse('2026-11-19 15:00:00 UTC'));
        $method = $this->createPaymentMethod(['codigo' => 'efectivo_web', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $session = $this->createOrderCashSession($actor, 'payment_session');

        $response = $this->actingAs($actor)->post('/pedidos', $this->paymentPayload($method, $session, $this->uuid(12), '5.00', '7.00'));

        $response->assertSessionHasNoErrors()->assertRedirect();
        $payment = DB::table('pagos_pedido')->first();
        $this->assertSame('5.00', $payment->monto);
        $this->assertSame('7.00', $payment->monto_recibido);
        $this->assertSame('2.00', $payment->vuelto);
        $this->assertEquals($actor->getKey(), $payment->usuario_id);
        $this->assertEquals($session, $payment->sesion_caja_id);
        $this->assertSame('2026-11-19 15:00:00', CarbonImmutable::parse($payment->ocurrido_en)->utc()->format('Y-m-d H:i:s'));
        $this->get(route('orders.show', $payment->pedido_id))->assertOk()->assertSee('Pago parcial')
            ->assertViewHas('finance', ['total' => '20.00', 'paid' => '5.00', 'balance' => '15.00', 'paymentStatus' => 'Pago parcial'])
            ->assertSee('19/11/2026 12:00')->assertSee(trim($actor->nombre.' '.$actor->apellido));
    }

    public function test_fully_paid_order_can_combine_cash_and_debit_without_fictitious_movements(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect], 'mixed');
        $session = $this->createOrderCashSession($actor, 'mixed_session');
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo_mixto', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $debit = $this->createPaymentMethod(['codigo' => 'debito_mixto', 'nombre' => 'Débito', 'afecta_efectivo' => false]);
        $payload = $this->paymentPayload($cash, $session, $this->uuid(13), '5.00', '5.00');
        $payload['payments'][] = ['payment_method_id' => $debit, 'amount' => '15.00', 'payment_uuid' => $this->uuid(114)];
        $this->actingAs($actor)->post('/pedidos', $payload)->assertSessionHasNoErrors();
        $this->post('/pedidos', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('pedidos', 1);
        $orderId = DB::table('pedidos')->where('clave_idempotencia', $this->uuid(13))->value('id');
        $this->assertDatabaseCount('pagos_pedido', 2);
        $this->assertDatabaseCount('movimientos_caja', 1);
        $this->assertDatabaseHas('movimientos_caja', ['monto' => '5.00', 'sesion_caja_id' => $session]);
        $this->assertDatabaseHas('pedidos', ['id' => $orderId, 'estado' => 'pendiente']);
        $this->get(route('orders.show', $orderId))->assertOk()->assertSee('Pagado')
            ->assertViewHas('finance', ['total' => '20.00', 'paid' => '20.00', 'balance' => '0.00', 'paymentStatus' => 'Pagado']);
    }

    public function test_normal_transitions_are_available_only_with_manage_permission(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersManage], 'transition');
        $this->actingAs($actor)->post('/pedidos', $this->orderPayload($this->uuid(20)))->assertSessionHasNoErrors();
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
        $method = $this->createOrderPaymentMethod('cancel_method');
        $session = $this->createOrderCashSession($actor, 'cancel_session');
        $this->actingAs($actor)->post('/pedidos', $this->paymentPayload($method, $session, $this->uuid(30), '4.00'))->assertSessionHasNoErrors();
        $orderId = (int) DB::table('pedidos')->where('clave_idempotencia', $this->uuid(30))->value('id');

        $this->actingAs($actor)->post("/pedidos/{$orderId}/cancelar", [
            'command_uuid' => $this->uuid(31),
            'reason' => 'Cliente desistió',
        ])->assertRedirect();

        $this->assertSame('cancelado', DB::table('pedidos')->where('id', $orderId)->value('estado'));
        $this->assertSame('devolucion', DB::table('pagos_pedido')->where('pedido_id', $orderId)->orderByDesc('id')->value('tipo'));
    }

    public function test_previous_cash_session_is_not_offered_or_accepted_for_order_payments(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect], 'previous_session');
        $method = $this->createOrderPaymentMethod('previous_method');
        $session = $this->createOrderCashSession($actor, 'previous_register');
        $this->travel(1)->days();

        $this->actingAs($actor)->get(route('orders.create'))->assertOk()->assertViewHas('cashSessions', []);
        $this->from(route('orders.create'))->post('/pedidos', $this->paymentPayload($method, $session, $this->uuid(32), '4.00'))
            ->assertRedirect(route('orders.create'))->assertSessionHasErrors();
        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('pagos_pedido', 0);
        $this->assertDatabaseCount('movimientos_caja', 0);
    }

    public function test_initial_payment_cannot_use_another_register_or_skip_session(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect], 'other_cash');
        $method = $this->createOrderPaymentMethod('other_cash');
        $other = $this->createOrderCashSession($actor, 'other_cash', principal: false);
        $payload = $this->paymentPayload($method, $other, $this->uuid(33), '5.00');
        $this->actingAs($actor)->get('/pedidos/crear')->assertViewHas('cashSessions', []);
        $this->from('/pedidos/crear')->post('/pedidos', $payload)->assertSessionHasErrors('order');
        $this->post('/pedidos', [...$payload, 'cash_session_id' => null])->assertSessionHasErrors('cash_session_id');
        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('pagos_pedido', 0);
    }

    public function test_free_order_with_mixed_payments_cannot_be_cancelled_without_a_traceable_refund(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersCollect, PermissionCode::OrdersCancel], 'mixed_cancel');
        $session = $this->createOrderCashSession($actor, 'mixed_cancel');
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo_cancel', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $debit = $this->createPaymentMethod(['codigo' => 'debito_cancel', 'nombre' => 'Débito', 'afecta_efectivo' => false]);
        $payload = $this->paymentPayload($cash, $session, $this->uuid(34), '5.00', '5.00');
        $payload['payments'][] = ['payment_method_id' => $debit, 'amount' => '15.00', 'payment_uuid' => $this->uuid(135)];
        $this->actingAs($actor)->post('/pedidos', $payload)->assertSessionHasNoErrors();
        $order = Pedido::query()->firstOrFail();
        $this->post(route('orders.cancel', $order), ['command_uuid' => $this->uuid(35), 'reason' => 'Solicitado por cliente'])
            ->assertSessionHasErrors(['order' => 'No se puede cancelar porque hay varios pagos pendientes de devolución. Pide ayuda a la persona encargada de Caja.']);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->estado);
        $this->assertDatabaseCount('pagos_pedido', 2);
        $this->assertDatabaseCount('movimientos_caja', 1);
        $this->assertDatabaseCount('transiciones_pedido', 1);
    }

    public function test_free_delivery_rolls_back_sale_and_final_cash_if_the_transition_fails(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersDeliver, PermissionCode::OrdersCollect], 'free_rollback');
        $session = $this->createOrderCashSession($actor, 'free_rollback');
        $cash = $this->createPaymentMethod(['codigo' => 'efectivo_rollback', 'nombre' => 'Efectivo', 'afecta_efectivo' => true]);
        $this->actingAs($actor)->post('/pedidos', $this->orderPayload($this->uuid(36)))->assertSessionHasNoErrors();
        $order = Pedido::query()->firstOrFail();
        DB::table('pedidos')->where('id', $order->id)->update(['estado' => 'listo']);
        DB::statement("ALTER TABLE transiciones_pedido ADD CONSTRAINT prueba_entrega_libre_falla CHECK (estado_nuevo <> 'entregado')");

        try {
            $this->withoutExceptionHandling()->post(route('orders.deliver', $order), [
                'command_uuid' => $this->uuid(37),
                'final_payment' => [
                    'payment_method_id' => $cash, 'cash_session_id' => $session,
                    'amount' => '20.00', 'received_amount' => '20.00', 'payment_uuid' => $this->uuid(38),
                ],
            ]);
            $this->fail('La prueba debía provocar un fallo al guardar la transición.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('prueba_entrega_libre_falla', $exception->getMessage());
        }
        $this->assertSame(OrderStatus::Ready, $order->fresh()->estado);
        foreach (['ventas', 'pagos_pedido', 'movimientos_caja', 'movimientos_inventario'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
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

        $url = '/pedidos/'.$fixture['orderId'].'/entregar';
        $payload = [
            'command_uuid' => $this->uuid(50),
            'final_payment' => [
                'payment_method_id' => $fixture['paymentMethodId'],
                'cash_session_id' => $fixture['cashSessionId'],
                'amount' => '10.00',
                'payment_uuid' => $this->uuid(51),
            ],
        ];
        $this->actingAs($actor)->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('entregado', DB::table('pedidos')->where('id', $fixture['orderId'])->value('estado'));
        $this->assertSame('10.00', DB::table('pagos_pedido')->where('clave_idempotencia', $this->uuid(51))->value('monto'));
        $this->assertDatabaseCount('pagos_pedido', 1);
        $this->assertDatabaseCount('ventas', 1);
    }

    public function test_delivery_rejects_a_final_payment_from_another_register_and_rolls_back_if_stock_is_short(): void
    {
        $actor = $this->createDeliveryActor([PermissionCode::OrdersDeliver, PermissionCode::OrdersCollect], 'delivery_stock');
        $fixture = $this->createReadyOrderFixture($actor, false, true, true, '10.00', '0.00');
        $otherSession = $this->createOrderCashSession($actor, 'other_register', principal: false);
        DB::table('articulos')->where('id', $fixture['articleId'])->update(['stock_actual' => '0.000']);
        $url = '/pedidos/'.$fixture['orderId'].'/entregar';
        $payload = [
            'command_uuid' => $this->uuid(52),
            'final_payment' => [
                'payment_method_id' => $fixture['paymentMethodId'],
                'cash_session_id' => $otherSession,
                'amount' => '10.00',
                'received_amount' => '10.00',
                'payment_uuid' => $this->uuid(53),
            ],
        ];
        $this->actingAs($actor)->post($url, $payload)->assertSessionHasErrors(['order' => 'La entrega requiere una sesión vigente de Caja principal.']);
        $payload['final_payment']['cash_session_id'] = $fixture['cashSessionId'];
        $this->post($url, $payload)->assertSessionHasErrors('order');
        $this->assertDatabaseHas('pedidos', ['id' => $fixture['orderId'], 'estado' => 'listo']);
        $this->assertDatabaseHas('articulos', ['id' => $fixture['articleId'], 'stock_actual' => '0.000']);
        foreach (['ventas', 'pagos_pedido', 'movimientos_caja', 'movimientos_inventario'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
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
            ->assertSee(DB::table('clientes')->where('id', $client)->value('nombre'))
            ->assertSee('Historial')
            ->assertSee('Artículos')
            ->assertSee('Cancelar pedido');
    }

    public function test_order_history_and_scheduled_delivery_use_santiago_without_changing_instants(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate, PermissionCode::OrdersManage, PermissionCode::OrdersDeliver], 'santiago_history');
        $this->travelTo(CarbonImmutable::parse('2026-11-08 21:58:51 UTC'));
        $this->actingAs($actor)->post('/pedidos', [
            ...$this->orderPayload($this->uuid(80), '25000'),
            'scheduled_delivery_at' => '2026-11-10T15:00',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Pedido creado.');

        $order = Pedido::query()->firstOrFail();
        foreach ([
            ['confirmar', '22:03:11', 81],
            ['preparacion', '22:04:46', 82],
            ['listo', '22:06:39', 83],
        ] as [$action, $utc, $uuid]) {
            $this->travelTo(CarbonImmutable::parse('2026-11-08 '.$utc.' UTC'));
            $this->post('/pedidos/'.$order->id.'/'.$action, ['command_uuid' => $this->uuid($uuid)])
                ->assertRedirect()->assertSessionHasNoErrors();
        }

        $detail = route('orders.show', $order);
        $this->get($detail)->assertOk()
            ->assertSeeInOrder(['Creado el 08/11/2026 18:58', 'Entrega programada: 10/11/2026 15:00'])
            ->assertSeeInOrder(['08/11/2026 19:06', '08/11/2026 19:04', '08/11/2026 19:03', '08/11/2026 18:58'])
            ->assertDontSee('08/11/2026 22:06')
            ->assertSee('class="form-alert form-alert-success" role="status"', false)
            ->assertSee('Para entregar este pedido, primero debe abrirse Caja principal para la jornada de hoy.')
            ->assertSee('Pide ayuda a una persona responsable de Caja.')
            ->assertDontSee('Confirmar entrega')->assertDontSee('Ir a Caja');
        $operator = $this->createOrderActor([PermissionCode::OrdersDeliver, PermissionCode::CashSessionsOpen], 'santiago_cash');
        $this->actingAs($operator)->get($detail)->assertOk()->assertSee('Ir a Caja')->assertDontSee('Confirmar entrega');

        $this->assertSame('2026-11-10T18:00:00+00:00', $order->entrega_programada_en->utc()->format(DATE_ATOM));
        $this->assertSame('2026-11-08T22:06:39+00:00', $order->fresh()->updated_at->utc()->format(DATE_ATOM));
        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('pagos_pedido', 0);
    }

    public function test_creation_filters_use_local_midnight_in_summer_and_winter(): void
    {
        $actor = $this->createOrderActor([PermissionCode::OrdersCreate], 'local_day');
        $this->actingAs($actor);
        foreach ([
            [90, '2026-11-09', '2026-11-08', '03:00:00'],
            [92, '2026-07-15', '2026-07-14', '04:00:00'],
        ] as [$uuid, $utcDate, $localDate, $midnight]) {
            $boundary = CarbonImmutable::parse($utcDate.' '.$midnight.' UTC');
            $this->travelTo($boundary->subSecond());
            $this->post('/pedidos', $this->orderPayload($this->uuid($uuid)))->assertSessionHasNoErrors();
            $this->travelTo($boundary);
            $this->post('/pedidos', $this->orderPayload($this->uuid($uuid + 1)))->assertSessionHasNoErrors();
            $this->get(route('orders.index', ['date_from' => $localDate, 'date_until' => $localDate]))
                ->assertOk()->assertSee('Creado desde')->assertSee('Creado hasta')
                ->assertViewHas('orders', fn ($orders) => $orders->pluck('clave_idempotencia')->all() === [$this->uuid($uuid)]);
        }
    }

    public function test_prepaid_delivery_rolls_back_if_the_jornada_changes_during_the_transaction(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 02:59:59 UTC'));
        $actor = $this->createDeliveryActor([
            PermissionCode::OrdersDeliver, PermissionCode::CashSessionsClose,
            PermissionCode::CashFundsAuthorize, PermissionCode::CashSessionsOpen,
        ], 'delivery_midnight');
        $fixture = $this->createReadyOrderFixture($actor);
        $this->actingAs($actor)->get(route('orders.show', $fixture['orderId']))
            ->assertOk()->assertSee('Confirmar entrega')
            ->assertSee('name="cash_session_id" value="'.$fixture['cashSessionId'].'"', false);

        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with($event->sql, 'insert into "transiciones_pedido"')) {
                $this->travelTo(CarbonImmutable::parse('2026-11-09 03:00:00 UTC'));
            }
        });

        $url = route('orders.deliver', $fixture['orderId']);
        $session = ['cash_session_id' => $fixture['cashSessionId']];
        $this->post($url, $session + ['command_uuid' => $this->uuid(94)])->assertSessionHasErrors('order');
        $this->post($url, $session + ['command_uuid' => $this->uuid(95)])->assertSessionHasErrors([
            'order' => 'La entrega requiere una sesión vigente de Caja principal.',
        ]);

        $cash = app(CashService::class);
        $cash->close($actor, $fixture['cashSessionId'], '0.00', '0.00', '2026-11-09', $this->uuid(97), true);
        $registerId = (int) DB::table('cajas')->where('codigo', 'principal')->value('id');
        $fundId = (int) DB::table('fondos_caja_autorizados')->where('caja_id', $registerId)
            ->where('fecha_operativa', '2026-11-09')->value('id');
        $replacement = $cash->open($actor, $registerId, $fundId, '2026-11-09', $this->uuid(98));
        $this->get(route('orders.show', $fixture['orderId']))
            ->assertOk()->assertSee('name="cash_session_id" value="'.$replacement->id.'"', false);
        $this->post($url, $session + ['command_uuid' => $this->uuid(96)])->assertSessionHasErrors([
            'order' => 'La entrega requiere una sesión vigente de Caja principal.',
        ]);
        $this->assertDatabaseHas('pedidos', ['id' => $fixture['orderId'], 'estado' => 'listo', 'entregado_en' => null]);
        $this->assertDatabaseCount('ventas', 0);
        $this->assertDatabaseCount('movimientos_inventario', 0);
        $this->assertDatabaseCount('pagos_pedido', 1);
        $this->assertDatabaseHas('pagos_pedido', ['pedido_id' => $fixture['orderId'], 'monto' => '10.00']);
    }

    /** @return array<string, mixed> */
    private function orderPayload(string $uuid, string $total = '20'): array
    {
        return [
            'command_uuid' => $uuid,
            'contact_name' => 'Ana de prueba',
            'contact_phone' => '(2) 2345-6789',
            'scheduled_delivery_at' => '2026-11-20T16:30',
            'description' => 'Dos kuchen de prueba',
            'agreed_total' => $total,
        ];
    }

    private function paymentPayload(int $method, int $session, string $uuid, string $amount, ?string $received = null): array
    {
        return [
            ...$this->orderPayload($uuid),
            'cash_session_id' => $session,
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
