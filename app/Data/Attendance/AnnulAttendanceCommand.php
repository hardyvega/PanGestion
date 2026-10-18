<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AnnulAttendanceCommand
{
    public string $idempotencyKey;

    public function __construct(
        public int $attendanceId,
        string $idempotencyKey,
        public string $reason,
    ) {
        if ($attendanceId < 1 || ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('La jornada y la clave de anulacion deben ser validas.');
        }
        if ($reason === '' || trim($reason) !== $reason) {
            throw new InvalidArgumentException('El motivo de anulacion debe ser no vacio y sin espacios extremos.');
        }
        $this->idempotencyKey = strtolower($idempotencyKey);
    }
}
