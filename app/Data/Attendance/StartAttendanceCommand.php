<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class StartAttendanceCommand
{
    public string $idempotencyKey;

    public function __construct(public int $workerId, string $idempotencyKey)
    {
        if ($workerId < 1 || ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('El trabajador y la clave de inicio deben ser validos.');
        }
        $this->idempotencyKey = strtolower($idempotencyKey);
    }
}
