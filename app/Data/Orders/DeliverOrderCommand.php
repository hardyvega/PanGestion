<?php

declare(strict_types=1);

namespace App\Data\Orders;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DeliverOrderCommand
{
    public function __construct(
        public int $orderId,
        string $commandUuid,
        public ?OrderPaymentData $finalPayment = null,
        public ?int $expectedCashSessionId = null,
    ) {
        if ($orderId < 1) {
            throw new InvalidArgumentException('orderId debe ser un entero positivo.');
        }
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        $this->commandUuid = strtolower($commandUuid);
    }

    public string $commandUuid;
}
