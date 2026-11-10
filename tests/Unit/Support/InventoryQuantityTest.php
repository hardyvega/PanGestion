<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\InventoryQuantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InventoryQuantityTest extends TestCase
{
    #[DataProvider('quantities')]
    public function test_it_formats_quantities_without_rounding(string $quantity, string $unit, bool $signed, string $expected): void
    {
        $this->assertSame($expected, InventoryQuantity::format($quantity, $unit, $signed));
    }

    public static function quantities(): array
    {
        return [
            'entry' => ['12.000', 'un', true, '+12 un'],
            'entry balance' => ['12.000', 'un', false, '12 un'],
            'exit' => ['-3.000', 'un', true, '−3 un'],
            'exit balance' => ['9.000', 'un', false, '9 un'],
            'mass' => ['2.125', 'kg', true, '+2,125 kg'],
            'volume' => ['-0.035', 'L', true, '−0,035 L'],
            'fractional unit' => ['0.250', 'un', false, '0,25 un'],
            'large stock' => ['1234567.050', 'kg', false, '1.234.567,05 kg'],
            'maximum precision' => ['99999999999.999', 'L', true, '+99.999.999.999,999 L'],
            'zero' => ['0.000', 'un', true, '0 un'],
        ];
    }
}
