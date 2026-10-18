<?php

declare(strict_types=1);

namespace App\Data\Sales;

use App\Enums\PaymentType;
use App\Support\Decimal;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SalePaymentData
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
        self::assertPositiveInteger($paymentMethodId, 'paymentMethodId');
        self::assertPositiveInteger($cashSessionId, 'cashSessionId');

        $this->amount = self::positiveMoney($amount, 'El monto debe ser positivo.');
        $this->receivedAmount = self::nonNegativeMoney($receivedAmount);
        $this->change = self::nonNegativeMoney($change);
        $this->commandUuid = self::uuid($commandUuid);

        if ($observation !== null && ($observation !== trim($observation) || trim($observation) === '')) {
            throw new InvalidArgumentException('La observacion debe ser un texto no vacio sin espacios extremos.');
        }
    }

    private static function positiveMoney(string $value, string $message): string
    {
        $normalized = Decimal::money($value);
        if (Decimal::compare($normalized, '0', 2) <= 0) {
            throw new InvalidArgumentException($message);
        }

        return $normalized;
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

    private static function uuid(string $value): string
    {
        if (! Str::isUuid($value)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }

        return strtolower($value);
    }

    private static function assertPositiveInteger(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$name} debe ser un entero positivo.");
        }
    }
}
