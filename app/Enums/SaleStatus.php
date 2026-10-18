<?php

declare(strict_types=1);

namespace App\Enums;

enum SaleStatus: string
{
    case Completed = 'completada';
    case Annulled = 'anulada';
}
