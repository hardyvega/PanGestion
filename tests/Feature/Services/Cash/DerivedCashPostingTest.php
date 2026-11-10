<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Cash;

use App\Data\Orders\CancelOrderCommand;
use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\OrderLineData;
use App\Data\Orders\OrderPaymentData;
use App\Data\Sales\AnnulSaleCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Cash\DerivedCashPostingFixtures;
use Tests\TestCase;

final class DerivedCashPostingTest extends TestCase
{
    use RefreshDatabase;
    use DerivedCashPostingFixtures;

    public function test_cash_sale_completion_creates_one_causal_cash_ingress_without_manual_cash_permission(): void
    {
        $actor = $this->createCashPostingActor([PermissionCode::SalesCreate]);
        $context = $this->createPostingContext($actor, affectsCash: true);
        $command = new CompleteSaleCommand(
            $this->cashPostingUuid(10),
            $context['cashSessionId'],
            [new SaleLineData($context['articleId'], '1.000', '4.00')],
            [new SalePaymentData(
                $context['paymentMethodId'],
                PaymentType::Charge,
                '4.00',
                $context['cashSessionId'],
                $this->cashPostingUuid(11),
            )],
        );

        $sale = $this->sales()->complete($actor, $command);
        $paymentId = DB::table('pagos_venta')->where('venta_id', $sale->getKey())->value('id');

        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_venta_id', $paymentId)->count());
        $this->assertDatabaseHas('movimientos_caja', [
            'pago_venta_id' => $paymentId,
            'tipo_movimiento' => 'ingreso',
            'monto' => '4.00',
            'usuario_id' => $actor->getKey(),
        ]);
    }

    public function test_non_cash_sale_completion_does_not_create_cash_movement(): void
    {
        $actor = $this->createCashPostingActor([PermissionCode::SalesCreate]);
        $context = $this->createPostingContext($actor, affectsCash: false);
        $sale = $this->sales()->complete($actor, new CompleteSaleCommand(
            $this->cashPostingUuid(12),
            $context['cashSessionId'],
            [new SaleLineData($context['articleId'], '1.000', '4.00')],
            [new SalePaymentData(
                $context['paymentMethodId'],
                PaymentType::Charge,
                '4.00',
                $context['cashSessionId'],
                $this->cashPostingUuid(13),
            )],
        ));

        $this->assertSame(0, DB::table('movimientos_caja')->where('pago_venta_id', '>', 0)->count());
        $this->assertNotNull($sale->getKey());
    }

    public function test_cash_sale_annulment_creates_one_causal_cash_egress_for_the_refund(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-24 15:00:00-03'));
        $actor = $this->createCashPostingActor([PermissionCode::SalesAnnul]);
        $context = $this->createPostingContext($actor, affectsCash: true);
        $fixture = $this->createHistoricalCashSale($actor, $context);

        $this->sales()->annul($actor, new AnnulSaleCommand(
            $fixture['saleId'],
            $this->cashPostingUuid(14),
            'Devolucion R8',
        ));

        $refundId = DB::table('pagos_venta')
            ->where('venta_id', $fixture['saleId'])
            ->where('tipo', 'devolucion')
            ->value('id');
        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_venta_id', $refundId)->count());
        $this->assertDatabaseHas('movimientos_caja', [
            'pago_venta_id' => $refundId,
            'tipo_movimiento' => 'egreso',
            'monto' => '4.00',
        ]);
    }

    public function test_cash_order_creation_creates_one_causal_cash_ingress(): void
    {
        $actor = $this->createCashPostingActor([
            PermissionCode::OrdersCreate,
            PermissionCode::OrdersCollect,
        ]);
        $context = $this->createPostingContext($actor, affectsCash: true, principal: true);
        $order = $this->orders()->create($actor, new CreateOrderCommand(
            $this->cashPostingUuid(15),
            $context['clientId'],
            [new OrderLineData($context['articleId'], '1.000', '4.00')],
            [new OrderPaymentData(
                $context['paymentMethodId'],
                PaymentType::Charge,
                '4.00',
                $context['cashSessionId'],
                $this->cashPostingUuid(16),
            )],
        ));
        $paymentId = DB::table('pagos_pedido')->where('pedido_id', $order->getKey())->value('id');

        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_pedido_id', $paymentId)->count());
        $this->assertDatabaseHas('movimientos_caja', [
            'pago_pedido_id' => $paymentId,
            'tipo_movimiento' => 'ingreso',
            'monto' => '4.00',
        ]);
    }

    public function test_non_cash_order_creation_does_not_create_cash_movement(): void
    {
        $actor = $this->createCashPostingActor([
            PermissionCode::OrdersCreate,
            PermissionCode::OrdersCollect,
        ]);
        $context = $this->createPostingContext($actor, affectsCash: false, principal: true);
        $order = $this->orders()->create($actor, new CreateOrderCommand(
            $this->cashPostingUuid(17),
            $context['clientId'],
            [new OrderLineData($context['articleId'], '1.000', '4.00')],
            [new OrderPaymentData(
                $context['paymentMethodId'],
                PaymentType::Charge,
                '4.00',
                $context['cashSessionId'],
                $this->cashPostingUuid(18),
            )],
        ));

        $this->assertSame(0, DB::table('movimientos_caja')->where('pago_pedido_id', '>', 0)->count());
        $this->assertNotNull($order->getKey());
    }

    public function test_cash_order_cancellation_creates_one_causal_cash_egress_for_the_refund(): void
    {
        $actor = $this->createCashPostingActor([PermissionCode::OrdersCancel]);
        $context = $this->createPostingContext($actor, affectsCash: true);
        $fixture = $this->createPendingOrderWithPayment($actor, $context);

        $this->orders()->cancel($actor, new CancelOrderCommand(
            $fixture['orderId'],
            $this->cashPostingUuid(19),
            'Cancelacion R8',
        ));

        $refundId = DB::table('pagos_pedido')
            ->where('pedido_id', $fixture['orderId'])
            ->where('tipo', 'devolucion')
            ->value('id');
        $this->assertSame(1, DB::table('movimientos_caja')->where('pago_pedido_id', $refundId)->count());
        $this->assertDatabaseHas('movimientos_caja', [
            'pago_pedido_id' => $refundId,
            'tipo_movimiento' => 'egreso',
            'monto' => '4.00',
        ]);
    }

    private function sales(): object
    {
        return $this->app->make(\App\Services\Sales\SaleService::class);
    }

    private function orders(): object
    {
        return $this->app->make(\App\Services\Orders\OrderService::class);
    }
}
