<?php

declare(strict_types=1);

namespace App\Data\Personnel;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class UpdateWorkerCommand
{
    public function __construct(
        public int $workerId,
        public string $code,
        public string $names,
        public string $surnames,
        public DateTimeInterface $startDate,
        public ?int $userId = null,
        public ?DateTimeInterface $endDate = null,
    ) {
        if ($workerId < 1) {
            throw new InvalidArgumentException('El trabajador debe tener un ID positivo.');
        }
        if ($code === '' || trim($code) === '') {
            throw new InvalidArgumentException('El codigo no puede estar vacio.');
        }
        foreach ([$names => 'Los nombres', $surnames => 'Los apellidos'] as $value => $label) {
            if ($value === '' || trim($value) !== $value) {
                throw new InvalidArgumentException($label.' debe ser un texto no vacio sin espacios extremos.');
            }
        }
        if ($endDate !== null && $endDate->format('Y-m-d') < $startDate->format('Y-m-d')) {
            throw new InvalidArgumentException('La fecha de termino no puede ser anterior al ingreso.');
        }
        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('El usuario vinculado debe tener un ID positivo.');
        }
    }
}
