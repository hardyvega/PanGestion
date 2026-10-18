<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ReceiptOriginType;
use App\Enums\ReceiptStatus;
use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptEnumsTest extends TestCase
{
    public function test_receipt_status_contains_exactly_the_three_approved_values(): void
    {
        $actual = array_map(
            static fn (ReceiptStatus $status): string => $status->value,
            ReceiptStatus::cases(),
        );

        $this->assertSame(['borrador', 'confirmada', 'anulada'], $actual);
        $this->assertCount(3, $actual);
    }

    public function test_receipt_origin_type_contains_exactly_the_three_approved_values(): void
    {
        $actual = array_map(
            static fn (ReceiptOriginType $origin): string => $origin->value,
            ReceiptOriginType::cases(),
        );

        $this->assertSame(['proveedor', 'interno', 'otro_autorizado'], $actual);
        $this->assertCount(3, $actual);
    }

    #[DataProvider('backedValues')]
    public function test_receipt_enums_round_trip_their_backed_values(string $enum, string $value): void
    {
        $case = $enum::from($value);

        $this->assertInstanceOf(BackedEnum::class, $case);
        $this->assertSame($value, $case->value);
    }

    public static function backedValues(): array
    {
        return [
            'status draft' => [ReceiptStatus::class, 'borrador'],
            'status confirmed' => [ReceiptStatus::class, 'confirmada'],
            'status annulled' => [ReceiptStatus::class, 'anulada'],
            'origin supplier' => [ReceiptOriginType::class, 'proveedor'],
            'origin internal' => [ReceiptOriginType::class, 'interno'],
            'origin authorized other' => [ReceiptOriginType::class, 'otro_autorizado'],
        ];
    }
}
