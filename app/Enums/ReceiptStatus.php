<?php

declare(strict_types=1);

namespace App\Enums;

enum ReceiptStatus: string
{
    case Draft = 'borrador';
    case Confirmed = 'confirmada';
    case Annulled = 'anulada';
}
