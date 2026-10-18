<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\SaleStatus;
use PHPUnit\Framework\TestCase;

final class SalesOrderEnumsTest extends TestCase
{
    public function test_sale_status_matches_the_persisted_values(): void
    {
        $this->assertSame(['completada', 'anulada'], array_map(
            static fn (SaleStatus $status): string => $status->value,
            SaleStatus::cases(),
        ));
    }

    public function test_order_status_matches_the_persisted_values(): void
    {
        $this->assertSame([
            'pendiente',
            'confirmado',
            'en_preparacion',
            'listo',
            'entregado',
            'cancelado',
        ], array_map(
            static fn (OrderStatus $status): string => $status->value,
            OrderStatus::cases(),
        ));
    }

    public function test_payment_type_matches_the_persisted_values(): void
    {
        $this->assertSame(['cobro', 'devolucion'], array_map(
            static fn (PaymentType $type): string => $type->value,
            PaymentType::cases(),
        ));
    }

    public function test_values_round_trip_through_the_backed_enum_api(): void
    {
        $this->assertSame(SaleStatus::Completed, SaleStatus::from('completada'));
        $this->assertSame(OrderStatus::Delivered, OrderStatus::from('entregado'));
        $this->assertSame(PaymentType::Refund, PaymentType::from('devolucion'));
    }
}
