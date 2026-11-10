<?php

namespace Tests\Support;

use App\Support\CommercialDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    protected function createOperationalCashSession(int $cashRegisterId, int $openingUserId): int
    {
        $openedAt = now('UTC');
        $date = CommercialDate::fromInstant($openedAt);
        $fundId = DB::table('fondos_caja_autorizados')->insertGetId([
            'caja_id' => $cashRegisterId,
            'fecha_operativa' => $date,
            'monto' => '0.00',
            'usuario_autorizante_id' => $openingUserId,
            'origen' => 'inicial',
            'clave_idempotencia' => (string) Str::uuid(),
            'motivo' => 'Fondo autorizado de prueba',
            'created_at' => $openedAt,
            'utilizada_en' => $openedAt,
        ]);

        return $this->createCashSession($cashRegisterId, $openingUserId, [
            'abierta_en' => $openedAt,
            'fecha_operativa' => $date,
            'fondo_autorizado_id' => $fundId,
            'clave_idempotencia_apertura' => (string) Str::uuid(),
        ]);
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
