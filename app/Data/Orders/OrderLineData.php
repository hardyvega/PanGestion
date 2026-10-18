<?php

declare(strict_types=1);

namespace App\Data\Orders;

use App\Support\Decimal;
use InvalidArgumentException;

final readonly class OrderLineData
{
    public string $quantity;

    public string $unitPrice;

    public function __construct(
        public int $articleId,
        string $quantity,
        string $unitPrice,
        public ?string $observation = null,
    ) {
        if ($articleId < 1) {
            throw new InvalidArgumentException('articleId debe ser un entero positivo.');
        }

        $this->quantity = Decimal::quantity($quantity);
        if (Decimal::compare($this->quantity, '0', 3) <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser positiva.');
        }

        $this->unitPrice = Decimal::money($unitPrice);
        if (Decimal::compare($this->unitPrice, '0', 2) < 0) {
            throw new InvalidArgumentException('El precio unitario no puede ser negativo.');
        }

        if ($observation !== null && ($observation !== trim($observation) || trim($observation) === '')) {
            throw new InvalidArgumentException('La observacion debe ser un texto no vacio sin espacios extremos.');
        }
    }
}
