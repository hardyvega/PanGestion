<?php

declare(strict_types=1);

namespace App\Data\Attendance;

use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RecordManualAttendanceCommand
{
    public string $startIdempotencyKey;
    public string $closeIdempotencyKey;

    public function __construct(
        public int $workerId,
        string $startIdempotencyKey,
        string $closeIdempotencyKey,
        public DateTimeInterface $startedAt,
        public DateTimeInterface $finishedAt,
    ) {
        if ($workerId < 1 || ! Str::isUuid($startIdempotencyKey) || ! Str::isUuid($closeIdempotencyKey)) {
            throw new InvalidArgumentException('El trabajador y las claves de manual deben ser validos.');
        }
        if ($startedAt > $finishedAt) {
            throw new InvalidArgumentException('El cierre no puede ser anterior al inicio.');
        }
        $this->startIdempotencyKey = strtolower($startIdempotencyKey);
        $this->closeIdempotencyKey = strtolower($closeIdempotencyKey);
    }
}
