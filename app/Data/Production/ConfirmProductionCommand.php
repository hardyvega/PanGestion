<?php

declare(strict_types=1);

namespace App\Data\Production;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ConfirmProductionCommand
{
    public string $commandUuid;

    public function __construct(
        public int $productionId,
        string $commandUuid,
    ) {
        if ($productionId < 1) {
            throw new InvalidArgumentException('productionId debe ser un entero positivo.');
        }

        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('La clave de idempotencia debe ser un UUID valido.');
        }

        $this->commandUuid = strtolower($commandUuid);
    }
}
