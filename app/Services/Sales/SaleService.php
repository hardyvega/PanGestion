<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\AnnulSaleCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Enums\PermissionCode;
use App\Models\User;
use App\Models\Venta;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\PostgresUniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SaleService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly SaleExecutionService $execution,
    ) {
    }

    public function complete(User $actor, CompleteSaleCommand $command): Venta
    {
        $this->permissions->authorize($actor, PermissionCode::SalesCreate);

        try {
            return DB::transaction(fn (): Venta => $this->execution->complete($actor, $command));
        } catch (QueryException $exception) {
            if (! PostgresUniqueViolation::for($exception, 'ventas_clave_idempotencia_unique')) {
                throw $exception;
            }

            // The failed transaction is already rolled back. Re-entering the
            // workflow opens a clean transaction and re-reads the winner.
            return DB::transaction(fn (): Venta => $this->execution->complete($actor, $command));
        }
    }

    public function annul(User $actor, AnnulSaleCommand $command): Venta
    {
        $this->permissions->authorize($actor, PermissionCode::SalesAnnul);

        return DB::transaction(fn (): Venta => $this->execution->annul($actor, $command));
    }
}
