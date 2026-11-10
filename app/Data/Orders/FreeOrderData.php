<?php

declare(strict_types=1);

namespace App\Data\Orders;

use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class FreeOrderData
{
    public const TEXT_LIMIT = 2000;

    public string $description;

    public ?string $specifications;

    public string $total;

    public function __construct(#[SensitiveParameter] string $description, #[SensitiveParameter] ?string $specifications, string|int $price)
    {
        $this->description = self::normalizeText($description)
            ?? throw new InvalidArgumentException('El detalle del pedido no es válido.');
        $this->specifications = $specifications === null ? null : self::normalizeText($specifications)
            ?? throw new InvalidArgumentException('Las especificaciones del pedido no son válidas.');
        $this->total = self::normalizePrice($price)
            ?? throw new InvalidArgumentException('El precio total acordado no es válido.');
    }

    public static function normalizeText(#[SensitiveParameter] string $value): ?string
    {
        $value = Str::trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $value !== '' && mb_strlen($value) <= self::TEXT_LIMIT && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            ? $value : null;
    }

    public static function normalizePrice(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);
        if (strlen($value) > 32 || ! preg_match('/^\$?[ \t]*(?:[0-9]{1,12}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,3})$/D', $value)) {
            return null;
        }

        $digits = ltrim(str_replace(['$', '.', ' ', "\t"], '', $value), '0');

        return $digits === '' ? null : $digits.'.00';
    }
}
