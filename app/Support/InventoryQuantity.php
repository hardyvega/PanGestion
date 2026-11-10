<?php

declare(strict_types=1);

namespace App\Support;

final class InventoryQuantity
{
    public static function format(string $quantity, ?string $unit, bool $signed = false): string
    {
        $normalized = Decimal::quantity($quantity);
        $negative = str_starts_with($normalized, '-');
        [$integer, $fraction] = explode('.', ltrim($normalized, '-'), 2);
        $fraction = rtrim($fraction, '0');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);
        $sign = $negative ? "\u{2212}" : ($signed && $normalized !== '0.000' ? '+' : '');

        return $sign.$grouped.($fraction === '' ? '' : ','.$fraction).($unit !== null && $unit !== '' ? ' '.$unit : '');
    }
}
