<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Exceptions\Inventory\InventoryMovementAlreadyReversedException;
use InvalidArgumentException;
use OutOfRangeException;
use Throwable;

final class UserFacingError
{
    public static function message(Throwable $exception, string $fallback = 'Revisa los datos antes de volver a intentarlo.'): string
    {
        if ($exception instanceof InsufficientStockException) {
            return 'No hay stock suficiente para esta operación. Revisa las cantidades y el stock disponible.';
        }
        if ($exception instanceof IdempotencyConflictException) {
            return 'Esta operación ya fue utilizada con otros datos. Revisa su estado antes de volver a intentarlo.';
        }
        if ($exception instanceof InventoryMovementAlreadyReversedException) {
            return 'Este movimiento ya fue anulado. Revisa el historial antes de continuar.';
        }

        $message = $exception->getMessage();
        if ($exception instanceof InventoryDomainException) {
            return trans('operations.domain')[$message] ?? ($message !== '' ? $message : $fallback);
        }
        if (($exception instanceof InvalidArgumentException || $exception instanceof OutOfRangeException)
            && isset(trans('operations.arguments')[$message])) {
            return trans('operations.arguments')[$message];
        }

        // Unexpected failures keep their server error status and normal reporting.
        throw $exception;
    }
}
