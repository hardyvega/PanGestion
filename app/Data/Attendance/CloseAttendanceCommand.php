<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CloseAttendanceCommand
{
    public string $idempotencyKey;

    public function __construct(
        public int $attendanceId,
        string $idempotencyKey,
        public ?DateTimeInterface $finishedAt = null,
    ) {
        if ($attendanceId < 1 || ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('La jornada y la clave de cierre deben ser validas.');
        }
        $this->idempotencyKey = strtolower($idempotencyKey);
    }
}
