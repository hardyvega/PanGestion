<?php

declare(strict_types=1);

namespace App\Data\Orders;

use App\Enums\PaymentType;
use App\Support\Decimal;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class OrderPaymentData
{
    public string $amount;

    public ?string $receivedAmount;

    public ?string $change;

    public string $commandUuid;

    public function __construct(
        public int $paymentMethodId,
        public PaymentType $type,
        string $amount,
        public int $cashSessionId,
        string $commandUuid,
        ?string $receivedAmount = null,
        ?string $change = null,
        public ?string $observation = null,
    ) {
        if ($paymentMethodId < 1 || $cashSessionId < 1) {
            throw new InvalidArgumentException('Los identificadores del pago deben ser positivos.');
        }

        $this->amount = Decimal::money($amount);
        if (Decimal::compare($this->amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('El monto debe ser positivo.');
        }

        $this->receivedAmount = self::nonNegativeMoney($receivedAmount);
        $this->change = self::nonNegativeMoney($change);
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        $this->commandUuid = strtolower($commandUuid);

        if ($observation !== null && ($observation !== trim($observation) || trim($observation) === '')) {
            throw new InvalidArgumentException('La observacion debe ser un texto no vacio sin espacios extremos.');
        }
    }

    private static function nonNegativeMoney(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Decimal::money($value);
        if (Decimal::compare($normalized, '0', 2) < 0) {
            throw new InvalidArgumentException('El monto monetario no puede ser negativo.');
        }

        return $normalized;
    }
}
