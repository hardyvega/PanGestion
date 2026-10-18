<?php

declare(strict_types=1);

namespace App\Data\Sales;

use App\Support\Decimal;
use InvalidArgumentException;

final readonly class SaleLineData
{
    public string $quantity;

    public string $unitPrice;

    public function __construct(
        public int $articleId,
        string $quantity,
        string $unitPrice,
    ) {
        self::assertPositiveInteger($articleId, 'articleId');

        $normalizedQuantity = Decimal::quantity($quantity);
        if (Decimal::compare($normalizedQuantity, '0', 3) <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser positiva.');
        }

        $normalizedUnitPrice = Decimal::money($unitPrice);
        if (Decimal::compare($normalizedUnitPrice, '0', 2) < 0) {
            throw new InvalidArgumentException('El precio unitario no puede ser negativo.');
        }

        $this->quantity = $normalizedQuantity;
        $this->unitPrice = $normalizedUnitPrice;
    }

    private static function assertPositiveInteger(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$name} debe ser un entero positivo.");
        }
    }
}
