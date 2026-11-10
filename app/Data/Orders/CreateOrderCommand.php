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
        public ?int $clientId,
        public array $lines,
        public array $payments = [],
        public ?DateTimeInterface $scheduledDeliveryAt = null,
        public ?string $observation = null,
        #[\SensitiveParameter] public ?OrderContact $contact = null,
        #[\SensitiveParameter] public ?FreeOrderData $freeOrder = null,
    ) {
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        $this->commandUuid = strtolower($commandUuid);

        if ($clientId !== null && $clientId < 1) {
            throw new InvalidArgumentException('clientId debe ser un entero positivo.');
        }
        if ($clientId !== null && $contact !== null) {
            throw new InvalidArgumentException('El pedido solo admite una fuente de contacto.');
        }
        if ($clientId === null && ($contact === null || $scheduledDeliveryAt === null)) {
            throw new InvalidArgumentException('El pedido requiere contacto y fecha de entrega.');
        }
        if (! array_is_list($lines) || ($freeOrder === null && $lines === [])) {
            throw new InvalidArgumentException('El pedido de catálogo requiere una lista de artículos.');
        }
        if ($freeOrder !== null && ($lines !== [] || $clientId !== null || $observation !== null)) {
            throw new InvalidArgumentException('El pedido libre requiere contacto propio y no admite líneas de catálogo ni observaciones separadas.');
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
