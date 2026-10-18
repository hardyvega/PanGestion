<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\Orders\CancelOrderCommand;
use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\DeliverOrderCommand;
use App\Data\Orders\OrderLineData;
use App\Data\Orders\OrderPaymentData;
use App\Data\Orders\TransitionOrderCommand;
use App\Data\Sales\AnnulSaleCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Data\Sales\SaleLineData;
use App\Data\Sales\SalePaymentData;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TypeError;

final class SalesOrderDataTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const SECOND_UUID = '22222222-2222-4222-8222-222222222222';

    public function test_sale_line_data_normalizes_quantity_and_money_without_floats(): void
    {
        $line = new SaleLineData(7, '2', '12');

        $this->assertSame(7, $line->articleId);
        $this->assertSame('2.000', $line->quantity);
        $this->assertSame('12.00', $line->unitPrice);
    }

    public function test_sale_payment_data_validates_and_normalizes_amounts(): void
    {
        $payment = new SalePaymentData(
            paymentMethodId: 3,
            type: PaymentType::Charge,
            amount: '10',
            cashSessionId: 4,
            commandUuid: self::UUID,
            receivedAmount: '15',
            change: '5',
            observation: 'Caja',
        );

        $this->assertSame('10.00', $payment->amount);
        $this->assertSame('15.00', $payment->receivedAmount);
        $this->assertSame('5.00', $payment->change);
        $this->assertSame('Caja', $payment->observation);
    }

    public function test_complete_sale_command_requires_nonempty_typed_lines_and_payments(): void
    {
        $line = new SaleLineData(7, '1', '12.50');
        $payment = new SalePaymentData(3, PaymentType::Charge, '12.50', 4, self::SECOND_UUID);
        $command = new CompleteSaleCommand(self::UUID, 4, [$line], [$payment], 9);

        $this->assertSame([$line], $command->lines);
        $this->assertSame([$payment], $command->payments);
        $this->assertSame(9, $command->clientId);
    }

    public function test_annul_sale_command_requires_a_nonblank_reason(): void
    {
        $command = new AnnulSaleCommand(15, self::UUID, 'Correccion de cobro');

        $this->assertSame(15, $command->saleId);
        $this->assertSame('Correccion de cobro', $command->reason);
    }

    public function test_order_line_and_payment_data_are_typed_and_normalized(): void
    {
        $line = new OrderLineData(7, '3', '4.5', 'Sin azucar');
        $payment = new OrderPaymentData(3, PaymentType::Charge, '13.50', 4, self::UUID);

        $this->assertSame('3.000', $line->quantity);
        $this->assertSame('4.50', $line->unitPrice);
        $this->assertSame('Sin azucar', $line->observation);
        $this->assertSame('13.50', $payment->amount);
    }

    public function test_create_order_command_accepts_optional_initial_payments(): void
    {
        $line = new OrderLineData(7, '1', '12.50');
        $command = new CreateOrderCommand(self::UUID, 20, [$line]);

        $this->assertSame([], $command->payments);
        $this->assertSame(20, $command->clientId);
    }

    public function test_transition_order_command_carries_the_target_and_optional_reason(): void
    {
        $command = new TransitionOrderCommand(15, self::UUID, OrderStatus::Confirmed);

        $this->assertSame(OrderStatus::Confirmed, $command->target);
        $this->assertNull($command->reason);
    }

    public function test_deliver_order_command_can_carry_the_final_payment(): void
    {
        $payment = new OrderPaymentData(3, PaymentType::Charge, '13.50', 4, self::SECOND_UUID);
        $command = new DeliverOrderCommand(15, self::UUID, $payment);

        $this->assertSame($payment, $command->finalPayment);
    }

    public function test_cancel_order_command_requires_a_reason(): void
    {
        $command = new CancelOrderCommand(15, self::UUID, 'Cliente desiste');

        $this->assertSame('Cliente desiste', $command->reason);
    }

    public function test_invalid_decimal_uuid_actor_and_line_values_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SaleLineData(0, '1', '2');
    }

    public function test_invalid_uuid_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnnulSaleCommand(1, 'invalid', 'Motivo');
    }

    public function test_command_lists_must_contain_the_expected_dto_types(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CompleteSaleCommand(self::UUID, 1, [new OrderLineData(1, '1', '1')], []);
    }

    public function test_dto_decimal_arguments_do_not_accept_floats(): void
    {
        $this->expectException(TypeError::class);
        new SaleLineData(1, 1.0, '2');
    }

    public function test_all_authorized_dtos_are_readonly_classes(): void
    {
        foreach ([
            SaleLineData::class,
            SalePaymentData::class,
            CompleteSaleCommand::class,
            AnnulSaleCommand::class,
            OrderLineData::class,
            OrderPaymentData::class,
            CreateOrderCommand::class,
            TransitionOrderCommand::class,
            DeliverOrderCommand::class,
            CancelOrderCommand::class,
        ] as $class) {
            $this->assertTrue((new ReflectionClass($class))->isReadOnly(), $class);
        }
    }
}
