<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pendiente';
    case Confirmed = 'confirmado';
    case InPreparation = 'en_preparacion';
    case Ready = 'listo';
    case Delivered = 'entregado';
    case Cancelled = 'cancelado';
}
