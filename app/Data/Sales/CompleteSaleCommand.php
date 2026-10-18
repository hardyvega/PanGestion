<?php

declare(strict_types=1);

namespace App\Data\Sales;

use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CompleteSaleCommand
{
    /** @param list<SaleLineData> $lines @param list<SalePaymentData> $payments */
    public function __construct(
        string $commandUuid,
        public int $cashSessionId,
        public array $lines,
        public array $payments,
        public ?int $clientId = null,
        public ?string $observation = null,
        public ?DateTimeInterface $completedAt = null,
    ) {
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }

        $this->commandUuid = strtolower($commandUuid);
        self::assertPositiveInteger($cashSessionId, 'cashSessionId');
        if ($clientId !== null) {
            self::assertPositiveInteger($clientId, 'clientId');
        }

        self::assertNonEmptyList($lines, SaleLineData::class, 'lines');
        self::assertNonEmptyList($payments, SalePaymentData::class, 'payments');

        if ($observation !== null && ($observation !== trim($observation) || trim($observation) === '')) {
            throw new InvalidArgumentException('La observacion debe ser un texto no vacio sin espacios extremos.');
        }
    }

    public string $commandUuid;

    private static function assertPositiveInteger(int $value, string $name): void
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$name} debe ser un entero positivo.");
        }
    }

    private static function assertNonEmptyList(array $values, string $expectedClass, string $name): void
    {
        if ($values === [] || ! array_is_list($values)) {
            throw new InvalidArgumentException("{$name} debe ser una lista no vacia.");
        }

        foreach ($values as $value) {
            if (! $value instanceof $expectedClass) {
                throw new InvalidArgumentException("{$name} contiene un elemento no valido.");
            }
        }
    }
}
