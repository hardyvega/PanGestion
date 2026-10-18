<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    private function __construct()
    {
    }

    public static function clp(string $amount): string
    {
        $normalized = Decimal::money($amount);
        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;
        [$integer] = explode('.', $unsigned, 2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return ($negative ? '-$' : '$').$grouped;
    }
}
