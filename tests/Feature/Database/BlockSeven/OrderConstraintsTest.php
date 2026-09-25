<?php

namespace Tests\Feature\Database\BlockSeven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\TestCase;

class OrderConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use RefreshDatabase;

    private int $clientId;

    private int $creatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientId = $this->createClient();
        $this->creatorId = $this->createUser($this->createRole());
    }

    public function test_order_can_be_created_with_minimum_data_and_defaults(): void
    {
        $orderId = $this->createOrder($this->clientId, $this->creatorId);
        $order = DB::table('pedidos')->where('id', $orderId)->first();

        $this->assertNotNull($order);
        $this->assertSame($orderId, $order->id);
        $this->assertSame($this->clientId, $order->cliente_id);
        $this->assertSame($this->creatorId, $order->usuario_creador_id);
        $this->assertSame('pendiente', $order->estado);
        $this->assertNull($order->entrega_programada_en);
        $this->assertNull($order->entregado_en);
        $this->assertNull($order->cancelado_en);
        $this->assertNull($order->observacion);
        $this->assertNotNull($order->created_at);
        $this->assertNotNull($order->updated_at);
    }

    #[DataProvider('validOrderStatesAndFinalEvents')]
    public function test_valid_order_state_and_final_events_are_accepted(
        string $state,
        ?string $deliveredAt,
        ?string $cancelledAt,
    ): void {
        $orderId = $this->createOrder($this->clientId, $this->creatorId, [
            'estado' => $state,
            'entregado_en' => $deliveredAt,
            'cancelado_en' => $cancelledAt,
        ]);
        $order = DB::table('pedidos')->where('id', $orderId)->first();

        $this->assertSame($state, $order->estado);
        $this->assertSame($deliveredAt === null, $order->entregado_en === null);
        $this->assertSame($cancelledAt === null, $order->cancelado_en === null);
    }

    public static function validOrderStatesAndFinalEvents(): array
    {
        return [
            'pending' => ['pendiente', null, null],
            'confirmed' => ['confirmado', null, null],
            'in preparation' => ['en_preparacion', null, null],
            'ready' => ['listo', null, null],
            'delivered' => ['entregado', '2026-09-21 12:00:00-03', null],
            'cancelled' => ['cancelado', null, '2026-09-21 12:00:00-03'],
        ];
    }

    #[DataProvider('invalidOrderStates')]
    public function test_invalid_order_state_is_rejected(string $state): void
    {
        $this->expectException(QueryException::class);
        $this->createOrder($this->clientId, $this->creatorId, ['estado' => $state]);
    }

    public static function invalidOrderStates(): array
    {
        return [
            'empty' => [''],
            'capitalized' => ['Pendiente'],
            'uppercase' => ['PENDIENTE'],
            'other' => ['otro'],
            'wrong delivered form' => ['entregada'],
        ];
    }

    #[DataProvider('invalidOrderFinalEvents')]
    public function test_invalid_order_final_event_combination_is_rejected(
        string $state,
        ?string $deliveredAt,
        ?string $cancelledAt,
    ): void {
        $this->expectException(QueryException::class);
        $this->createOrder($this->clientId, $this->creatorId, [
            'estado' => $state,
            'entregado_en' => $deliveredAt,
            'cancelado_en' => $cancelledAt,
        ]);
    }

    public static function invalidOrderFinalEvents(): array
    {
        $timestamp = '2026-09-21 12:00:00-03';

        return [
            'delivered without timestamp' => ['entregado', null, null],
            'delivered with cancellation timestamp' => ['entregado', $timestamp, $timestamp],
            'cancelled without timestamp' => ['cancelado', null, null],
            'cancelled with delivery timestamp' => ['cancelado', $timestamp, $timestamp],
            'normal state with delivery timestamp' => ['confirmado', $timestamp, null],
            'normal state with cancellation timestamp' => ['listo', null, $timestamp],
            'normal state with both timestamps' => ['pendiente', $timestamp, $timestamp],
        ];
    }

    #[DataProvider('validOrderObservations')]
    public function test_valid_order_observation_is_preserved(?string $observation): void
    {
        $orderId = $this->createOrder(
            $this->clientId,
            $this->creatorId,
            ['observacion' => $observation],
        );

        $this->assertSame($observation, DB::table('pedidos')->where('id', $orderId)->value('observacion'));
    }

    public static function validOrderObservations(): array
    {
        return [
            'null' => [null],
            'text' => ['Entregar en mostrador'],
        ];
    }

    #[DataProvider('invalidOrderObservations')]
    public function test_invalid_order_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createOrder($this->clientId, $this->creatorId, ['observacion' => $observation]);
    }

    public static function invalidOrderObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Entregar en mostrador'],
            'trailing space' => ['Entregar en mostrador '],
        ];
    }

    public function test_order_accepts_null_scheduled_delivery(): void
    {
        $orderId = $this->createOrder(
            $this->clientId,
            $this->creatorId,
            ['entrega_programada_en' => null],
        );

        $this->assertNull(DB::table('pedidos')->where('id', $orderId)->value('entrega_programada_en'));
    }

    public function test_order_can_exist_without_details(): void
    {
        $orderId = $this->createOrder($this->clientId, $this->creatorId);

        $this->assertSame(1, DB::table('pedidos')->where('id', $orderId)->count());
        $this->assertSame(0, DB::table('detalle_pedidos')->where('pedido_id', $orderId)->count());
    }
}
