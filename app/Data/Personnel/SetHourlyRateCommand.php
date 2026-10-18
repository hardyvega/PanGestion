<?php

declare(strict_types=1);

namespace App\Data\Personnel;

use App\Support\Decimal;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class SetHourlyRateCommand
{
    public string $hourlyRate;

    public function __construct(
        public int $workerId,
        string $hourlyRate,
        public DateTimeInterface $effectiveFrom,
        public ?string $observation = null,
    ) {
        if ($workerId < 1) {
            throw new InvalidArgumentException('El trabajador debe tener un ID positivo.');
        }
        $this->hourlyRate = Decimal::money($hourlyRate);
        if (Decimal::sign($this->hourlyRate) <= 0) {
            throw new InvalidArgumentException('La tarifa por hora debe ser positiva.');
        }
        if ($observation !== null && ($observation === '' || trim($observation) !== $observation)) {
            throw new InvalidArgumentException('La observacion debe ser no vacia y sin espacios extremos.');
        }
    }
}
