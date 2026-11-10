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
        return self::formatClp(Decimal::money($amount));
    }

    public static function clpTotal(string $amount): string
    {
        return self::formatClp(Decimal::normalize($amount, 2), includeCents: true);
    }

    private static function formatClp(string $normalized, bool $includeCents = false): string
    {
        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;
        [$integer, $cents] = explode('.', $unsigned, 2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer);

        return ($negative ? '-$' : '$').$grouped.($includeCents && $cents !== '00' ? ','.$cents : '');
    }
}
