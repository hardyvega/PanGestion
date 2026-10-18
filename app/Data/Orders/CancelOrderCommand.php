<?php

declare(strict_types=1);

namespace App\Data\Orders;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CancelOrderCommand
{
    public function __construct(
        public int $orderId,
        string $commandUuid,
        public string $reason,
    ) {
        if ($orderId < 1) {
            throw new InvalidArgumentException('orderId debe ser un entero positivo.');
        }
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        if ($reason !== trim($reason) || trim($reason) === '') {
            throw new InvalidArgumentException('El motivo de cancelacion debe ser un texto no vacio sin espacios extremos.');
        }
        $this->commandUuid = strtolower($commandUuid);
    }

    public string $commandUuid;
}
