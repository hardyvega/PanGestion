<?php

declare(strict_types=1);

namespace App\Data\Sales;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AnnulSaleCommand
{
    public function __construct(
        public int $saleId,
        string $commandUuid,
        public string $reason,
    ) {
        if ($saleId < 1) {
            throw new InvalidArgumentException('saleId debe ser un entero positivo.');
        }
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }
        if ($reason === trim($reason) && trim($reason) !== '') {
            $this->commandUuid = strtolower($commandUuid);
            return;
        }

        throw new InvalidArgumentException('El motivo de anulacion debe ser un texto no vacio sin espacios extremos.');
    }

    public string $commandUuid;
}
