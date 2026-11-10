<?php

declare(strict_types=1);

namespace App\Data\Orders;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class OrderContact
{
    public string $phone;

    public function __construct(public string $name, #[SensitiveParameter] string $phone)
    {
        if (trim($name) === '' || trim($name) !== $name || mb_strlen($name) > 150) {
            throw new InvalidArgumentException('El nombre del cliente no es válido.');
        }

        $this->phone = self::normalizePhone($phone)
            ?? throw new InvalidArgumentException('El teléfono de contacto no es válido.');
    }

    public static function normalizePhone(#[SensitiveParameter] string $phone): ?string
    {
        $phone = trim($phone);
        if (strlen($phone) > 50 || ! preg_match('/^\+?(?:[0-9 .-]|\([0-9]+\))+$/D', $phone)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '+') || str_starts_with($digits, '00')) {
            $international = str_starts_with($phone, '+') ? $digits : substr($digits, 2);

            if (str_starts_with($international, '56')) {
                return preg_match('/^56[2-9][0-9]{8}$/D', $international) ? '+'.$international : null;
            }

            return preg_match('/^[1-9][0-9]{7,14}$/D', $international) ? '+'.$international : null;
        }

        if (preg_match('/^56[2-9][0-9]{8}$/D', $digits)) {
            return '+'.$digits;
        }

        return preg_match('/^[2-9][0-9]{8}$/D', $digits) ? '+56'.$digits : null;
    }
}
