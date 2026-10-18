<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;
use LogicException;

final class SaleCorrelativeAllocator
{
    public function next(string $commercialDate): int
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() <= 0) {
            throw new LogicException('El correlativo requiere una transaccion externa activa.');
        }

        $query = $connection->table('correlativos_venta_diarios');
        $row = $query
            ->where('fecha_comercial', $commercialDate)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            $inserted = $query->insertOrIgnore([
                'fecha_comercial' => $commercialDate,
                'ultimo_numero' => 1,
            ]);

            if ($inserted === 1) {
                return 1;
            }

            $row = $query
                ->where('fecha_comercial', $commercialDate)
                ->lockForUpdate()
                ->first();
        }

        if ($row === null) {
            throw new LogicException('No se pudo reservar el correlativo de venta.');
        }

        $next = (int) $row->ultimo_numero + 1;

        $query
            ->where('fecha_comercial', $commercialDate)
            ->update([
                'ultimo_numero' => $next,
                'updated_at' => now(),
            ]);

        return $next;
    }
}
