<?php

declare(strict_types=1);

namespace App\Data\Reports;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class HourlyPayReportQuery
{
    public function __construct(
        public DateTimeInterface $from,
        public DateTimeInterface $until,
    ) {
        if ($from >= $until) {
            throw new InvalidArgumentException('El rango del reporte debe ser [desde,hasta) y no vacio.');
        }
    }
}
