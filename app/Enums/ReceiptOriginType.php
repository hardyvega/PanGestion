<?php

declare(strict_types=1);

namespace App\Enums;

enum ReceiptOriginType: string
{
    case Supplier = 'proveedor';
    case Internal = 'interno';
    case AuthorizedOther = 'otro_autorizado';
}
