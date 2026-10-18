<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Decimal;
use InvalidArgumentException;
use OutOfRangeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    #[DataProvider('quantityCanonicalValues')]
    public function test_quantity_returns_a_canonical_scale_three_string(string $input, string $expected): void
    {
        $this->assertSame($expected, Decimal::quantity($input));
    }

    public static function quantityCanonicalValues(): array
    {
        return [
            'integer' => ['1', '1.000'],
            'single decimal' => ['1.2', '1.200'],
            'contract scale' => ['1.230', '1.230'],
            'leading zeros' => ['0001.200', '1.200'],
            'negative value' => ['-0001.200', '-1.200'],
            'negative integer zero' => ['-0', '0.000'],
            'negative scale zero' => ['-0.000', '0.000'],
        ];
    }

    #[DataProvider('moneyCanonicalValues')]
    public function test_money_returns_a_canonical_scale_two_string(string $input, string $expected): void
    {
        $this->assertSame($expected, Decimal::money($input));
    }

    public static function moneyCanonicalValues(): array
    {
        return [
            'integer' => ['1', '1.00'],
            'single decimal' => ['1.2', '1.20'],
            'contract scale' => ['1.20', '1.20'],
            'leading zeros' => ['0001.20', '1.20'],
            'negative zero' => ['-0.0', '0.00'],
        ];
    }

    #[DataProvider('genericCanonicalValues')]
    public function test_normalize_uses_the_requested_scale(string $input, int $scale, string $expected): void
    {
        $this->assertSame($expected, Decimal::normalize($input, $scale));
    }

    public static function genericCanonicalValues(): array
    {
        return [
            'zero scale' => ['00012', 0, '12'],
            'scale three' => ['-0012.3', 3, '-12.300'],
            'scale twelve' => ['0.02', 12, '0.020000000000'],
        ];
    }

    #[DataProvider('invalidNumericInputs')]
    public function test_business_inputs_reject_invalid_numeric_formats(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Decimal::quantity($input);
    }

    public static function invalidNumericInputs(): array
    {
        return [
            'empty' => [''],
            'leading whitespace' => [' 1'],
            'trailing whitespace' => ['1 '],
            'internal whitespace' => ['1 0'],
            'explicit plus' => ['+1'],
            'decimal comma' => ['1,25'],
            'lowercase exponent' => ['1e3'],
            'uppercase exponent' => ['1E3'],
            'nan' => ['NaN'],
            'infinity' => ['Infinity'],
            'negative infinity' => ['-INF'],
            'arbitrary text' => ['pan'],
        ];
    }

    #[DataProvider('excessiveBusinessScaleValues')]
    public function test_business_inputs_reject_scale_instead_of_rounding_it(string $method, string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        match ($method) {
            'quantity' => Decimal::quantity($input),
            'money' => Decimal::money($input),
        };
    }

    public static function excessiveBusinessScaleValues(): array
    {
        return [
            'positive quantity' => ['quantity', '1.0001'],
            'negative quantity' => ['quantity', '-0.0001'],
            'positive money' => ['money', '1.001'],
            'negative money' => ['money', '-0.001'],
        ];
    }

    #[DataProvider('arithmeticValues')]
    public function test_arithmetic_is_exact_and_returns_the_requested_scale(
        string $operation,
        string $left,
        string $right,
        int $scale,
        string $expected,
    ): void {
        $actual = match ($operation) {
            'add' => Decimal::add($left, $right, $scale),
            'subtract' => Decimal::subtract($left, $right, $scale),
            'multiply' => Decimal::multiply($left, $right, $scale),
        };

        $this->assertSame($expected, $actual);
    }

    public static function arithmeticValues(): array
    {
        return [
            'addition' => ['add', '1.200', '2.345', 3, '3.545'],
            'addition with negative' => ['add', '-1.200', '0.200', 3, '-1.000'],
            'subtraction' => ['subtract', '5.000', '2.125', 3, '2.875'],
            'negative subtraction result' => ['subtract', '2', '5', 3, '-3.000'],
            'quantity multiplication' => ['multiply', '1.250', '2.000', 3, '2.500'],
            'intermediate multiplication' => ['multiply', '0.1', '0.2', 12, '0.020000000000'],
        ];
    }

    #[DataProvider('comparisonValues')]
    public function test_compare_returns_only_minus_one_zero_or_one(
        string $left,
        string $right,
        int $scale,
        int $expected,
    ): void {
        $this->assertSame($expected, Decimal::compare($left, $right, $scale));
    }

    public static function comparisonValues(): array
    {
        return [
            'less than' => ['1.000', '1.001', 3, -1],
            'equal after canonicalization' => ['1.0', '1.000', 3, 0],
            'greater than' => ['2.000', '-2.000', 3, 1],
        ];
    }

    #[DataProvider('signedValues')]
    public function test_sign_returns_only_minus_one_zero_or_one(string $value, int $expected): void
    {
        $this->assertSame($expected, Decimal::sign($value));
    }

    public static function signedValues(): array
    {
        return [
            'negative' => ['-0.001', -1],
            'negative integer' => ['-10', -1],
            'zero' => ['0', 0],
            'negative zero' => ['-0.000', 0],
            'positive' => ['0.001', 1],
        ];
    }

    #[DataProvider('zeroValues')]
    public function test_is_zero_recognizes_equivalent_zeros(string $value, bool $expected): void
    {
        $this->assertSame($expected, Decimal::isZero($value));
    }

    public static function zeroValues(): array
    {
        return [
            'integer zero' => ['0', true],
            'decimal zero' => ['0.000', true],
            'negative zero' => ['-0.00', true],
            'positive nonzero' => ['0.001', false],
            'negative nonzero' => ['-0.001', false],
        ];
    }

    #[DataProvider('roundingValues')]
    public function test_round_uses_half_away_from_zero(string $input, int $scale, string $expected): void
    {
        $this->assertSame($expected, Decimal::round($input, $scale));
    }

    public static function roundingValues(): array
    {
        return [
            'positive below half' => ['1.004', 2, '1.00'],
            'positive exact half' => ['1.005', 2, '1.01'],
            'negative below half' => ['-1.004', 2, '-1.00'],
            'negative exact half' => ['-1.005', 2, '-1.01'],
            'positive second half' => ['2.675', 2, '2.68'],
            'negative second half' => ['-2.675', 2, '-2.68'],
        ];
    }

    #[DataProvider('quantityRangeValues')]
    public function test_quantity_enforces_numeric_fourteen_three_range(string $input, bool $valid): void
    {
        if ($valid) {
            $this->assertSame($input, Decimal::quantity($input));

            return;
        }

        $this->expectException(OutOfRangeException::class);

        Decimal::quantity($input);
    }

    public static function quantityRangeValues(): array
    {
        return [
            'maximum positive' => ['99999999999.999', true],
            'maximum negative magnitude' => ['-99999999999.999', true],
            'first positive out of range' => ['100000000000.000', false],
            'first negative out of range' => ['-100000000000.000', false],
        ];
    }

    #[DataProvider('moneyRangeValues')]
    public function test_money_enforces_numeric_fourteen_two_range(string $input, bool $valid): void
    {
        if ($valid) {
            $this->assertSame($input, Decimal::money($input));

            return;
        }

        $this->expectException(OutOfRangeException::class);

        Decimal::money($input);
    }

    public static function moneyRangeValues(): array
    {
        return [
            'maximum positive' => ['999999999999.99', true],
            'maximum negative magnitude' => ['-999999999999.99', true],
            'first positive out of range' => ['1000000000000.00', false],
            'first negative out of range' => ['-1000000000000.00', false],
        ];
    }
}
