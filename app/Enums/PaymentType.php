<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentType: string
{
    case Charge = 'cobro';
    case Refund = 'devolucion';
}
