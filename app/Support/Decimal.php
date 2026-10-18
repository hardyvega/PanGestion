<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use OutOfRangeException;

final class Decimal
{
    private const QUANTITY_SCALE = 3;

    private const MONEY_SCALE = 2;

    private const MAX_QUANTITY = '99999999999.999';

    private const MAX_MONEY = '999999999999.99';

    private function __construct()
    {
    }

    public static function quantity(string $value): string
    {
        $normalized = self::normalize($value, self::QUANTITY_SCALE);
        self::assertWithinRange($normalized, self::MAX_QUANTITY, self::QUANTITY_SCALE);

        return $normalized;
    }

    public static function money(string $value): string
    {
        $normalized = self::normalize($value, self::MONEY_SCALE);
        self::assertWithinRange($normalized, self::MAX_MONEY, self::MONEY_SCALE);

        return $normalized;
    }

    public static function normalize(string $value, int $scale): string
    {
        self::assertScale($scale);
        self::assertValidFormat($value);

        if (self::fractionalDigits($value) > $scale) {
            throw new InvalidArgumentException('El valor decimal excede la escala permitida.');
        }

        return self::canonicalize($value, $scale);
    }

    public static function add(string $left, string $right, int $scale): string
    {
        self::assertOperationInputs($left, $right, $scale);
        $calculationScale = max(
            self::fractionalDigits($left),
            self::fractionalDigits($right),
            $scale,
        );

        return self::round(bcadd($left, $right, $calculationScale), $scale);
    }

    public static function subtract(string $left, string $right, int $scale): string
    {
        self::assertOperationInputs($left, $right, $scale);
        $calculationScale = max(
            self::fractionalDigits($left),
            self::fractionalDigits($right),
            $scale,
        );

        return self::round(bcsub($left, $right, $calculationScale), $scale);
    }

    public static function multiply(string $left, string $right, int $scale): string
    {
        self::assertOperationInputs($left, $right, $scale);
        $calculationScale = max(
            self::fractionalDigits($left) + self::fractionalDigits($right),
            $scale,
        );

        return self::round(bcmul($left, $right, $calculationScale), $scale);
    }

    public static function round(string $value, int $scale): string
    {
        self::assertScale($scale);
        self::assertValidFormat($value);

        $rounded = bcround($value, $scale, \RoundingMode::HalfAwayFromZero);

        return self::canonicalize($rounded, $scale);
    }

    public static function compare(string $left, string $right, int $scale): int
    {
        self::assertOperationInputs($left, $right, $scale);

        return bccomp($left, $right, $scale);
    }

    public static function sign(string $value): int
    {
        self::assertValidFormat($value);

        return bccomp($value, '0', self::fractionalDigits($value));
    }

    public static function isZero(string $value): bool
    {
        return self::sign($value) === 0;
    }

    private static function assertOperationInputs(string $left, string $right, int $scale): void
    {
        self::assertScale($scale);
        self::assertValidFormat($left);
        self::assertValidFormat($right);
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('La escala decimal no puede ser negativa.');
        }
    }

    private static function assertValidFormat(string $value): void
    {
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('El valor no tiene un formato decimal valido.');
        }
    }

    private static function fractionalDigits(string $value): int
    {
        $separator = strpos($value, '.');

        return $separator === false ? 0 : strlen($value) - $separator - 1;
    }

    private static function canonicalize(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($fraction, $scale, '0');
        $isZero = $integer === '0' && trim($fraction, '0') === '';
        $sign = $negative && ! $isZero ? '-' : '';

        return $scale === 0
            ? $sign.$integer
            : $sign.$integer.'.'.$fraction;
    }

    private static function assertWithinRange(string $value, string $maximum, int $scale): void
    {
        $magnitude = str_starts_with($value, '-') ? substr($value, 1) : $value;

        if (bccomp($magnitude, $maximum, $scale) > 0) {
            throw new OutOfRangeException('El valor decimal excede el rango permitido.');
        }
    }
}
