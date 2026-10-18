<?php

declare(strict_types=1);

namespace App\Enums;

enum AttendanceStatus: string
{
    case Open = 'abierta';
    case Closed = 'cerrada';
    case Annulled = 'anulada';
}
