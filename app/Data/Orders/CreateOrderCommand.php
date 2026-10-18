<?php

declare(strict_types=1);

namespace App\Data\Orders;

use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateOrderCommand
{
    /** @param list<OrderLineData> $lines @param list<OrderPaymentData> $payments */
    public function __construct(
        string $commandUuid,
        public int $clientId,
        public array $lines,
        public array $payments = [],
        public ?DateTimeInterface $scheduledDeliveryAt = null,
        public ?string $observation = null,
    ) {
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        $this->commandUuid = strtolower($commandUuid);

        if ($clientId < 1) {
            throw new InvalidArgumentException('clientId debe ser un entero positivo.');
        }
        if ($lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('lines debe ser una lista no vacia.');
        }
        foreach ($lines as $line) {
            if (! $line instanceof OrderLineData) {
                throw new InvalidArgumentException('lines contiene un elemento no valido.');
            }
        }
        if (! array_is_list($payments)) {
            throw new InvalidArgumentException('payments debe ser una lista.');
        }
        foreach ($payments as $payment) {
            if (! $payment instanceof OrderPaymentData) {
                throw new InvalidArgumentException('payments contiene un elemento no valido.');
            }
        }
        if ($observation !== null && ($observation !== trim($observation) || trim($observation) === '')) {
            throw new InvalidArgumentException('La observacion debe ser un texto no vacio sin espacios extremos.');
        }
    }

    public string $commandUuid;
}
