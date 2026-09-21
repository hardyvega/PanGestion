<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait CreatesBlockSixRecords
{
    protected function cashSessionData(int $cashRegisterId, int $openingUserId, array $overrides = []): array
    {
        return array_merge([
            'caja_id' => $cashRegisterId,
            'usuario_apertura_id' => $openingUserId,
            'monto_inicial' => '0.00',
        ], $overrides);
    }

    protected function createCashSession(int $cashRegisterId, int $openingUserId, array $overrides = []): int
    {
        return (int) DB::table('sesiones_caja')->insertGetId(
            $this->cashSessionData($cashRegisterId, $openingUserId, $overrides),
        );
    }

    protected function cashMovementData(int $cashSessionId, int $userId, array $overrides = []): array
    {
        return array_merge([
            'sesion_caja_id' => $cashSessionId,
            'usuario_id' => $userId,
            'tipo_movimiento' => 'ingreso',
            'monto' => '1.00',
            'concepto' => 'Movimiento manual',
        ], $overrides);
    }

    protected function createCashMovement(int $cashSessionId, int $userId, array $overrides = []): int
    {
        return (int) DB::table('movimientos_caja')->insertGetId(
            $this->cashMovementData($cashSessionId, $userId, $overrides),
        );
    }
}
