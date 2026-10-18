<?php

declare(strict_types=1);

namespace App\Data\Orders;

use App\Enums\OrderStatus;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TransitionOrderCommand
{
    public function __construct(
        public int $orderId,
        string $commandUuid,
        public OrderStatus $target,
        public ?string $reason = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {
        if ($orderId < 1) {
            throw new InvalidArgumentException('orderId debe ser un entero positivo.');
        }
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        $this->commandUuid = strtolower($commandUuid);

        if ($reason !== null && ($reason !== trim($reason) || trim($reason) === '')) {
            throw new InvalidArgumentException('El motivo debe ser un texto no vacio sin espacios extremos.');
        }
        if ($target === OrderStatus::Cancelled && $reason === null) {
            throw new InvalidArgumentException('La cancelacion requiere un motivo.');
        }
        if ($target !== OrderStatus::Cancelled && $reason !== null) {
            throw new InvalidArgumentException('Solo la cancelacion admite motivo.');
        }
    }

    public string $commandUuid;
}
