<?php

declare(strict_types=1);

namespace App\Data\Personnel;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class CreateWorkerCommand
{
    public function __construct(
        public string $code,
        public string $names,
        public string $surnames,
        public DateTimeInterface $startDate,
        public ?int $userId = null,
        public ?DateTimeInterface $endDate = null,
    ) {
        if ($code === '' || trim($code) === '') {
            throw new InvalidArgumentException('El codigo no puede estar vacio.');
        }
        self::text($names, 'Los nombres');
        self::text($surnames, 'Los apellidos');
        self::dates($startDate, $endDate);
        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('El usuario vinculado debe tener un ID positivo.');
        }
    }

    private static function text(string $value, string $label): void
    {
        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException($label.' debe ser un texto no vacio sin espacios extremos.');
        }
    }

    private static function dates(DateTimeInterface $start, ?DateTimeInterface $end): void
    {
        if ($end !== null && $end->format('Y-m-d') < $start->format('Y-m-d')) {
            throw new InvalidArgumentException('La fecha de termino no puede ser anterior al ingreso.');
        }
    }
}
