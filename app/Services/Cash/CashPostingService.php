<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CashPostingService
{
    public function postSalePayment(int $paymentId): void
    {
        $this->post('pagos_venta', 'pago_venta_id', $paymentId);
    }

    public function postOrderPayment(int $paymentId): void
    {
        $this->post('pagos_pedido', 'pago_pedido_id', $paymentId);
    }

    private function post(string $paymentTable, string $causalColumn, int $paymentId): void
    {
        $payment = DB::table($paymentTable.' as pagos')
            ->join('metodos_pago as metodos', 'metodos.id', '=', 'pagos.metodo_pago_id')
            ->where('pagos.id', $paymentId)
            ->first([
                'pagos.id',
                'pagos.sesion_caja_id',
                'pagos.usuario_id',
                'pagos.tipo',
                'pagos.monto',
                'pagos.ocurrido_en',
                'metodos.afecta_efectivo',
            ]);

        if ($payment === null) {
            throw (new ModelNotFoundException())->setModel($paymentTable, [$paymentId]);
        }

        if (! (bool) $payment->afecta_efectivo) {
            return;
        }

        $movementType = $payment->tipo === 'devolucion' ? 'egreso' : 'ingreso';
        $existing = DB::table('movimientos_caja')
            ->where($causalColumn, $paymentId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ((int) $existing->sesion_caja_id !== (int) $payment->sesion_caja_id
                || (int) $existing->usuario_id !== (int) $payment->usuario_id
                || $existing->tipo_movimiento !== $movementType
                || Decimal::money((string) $existing->monto) !== Decimal::money((string) $payment->monto)) {
                throw new IdempotencyConflictException('El movimiento de caja causal no coincide con el pago.');
            }

            return;
        }

        DB::table('movimientos_caja')->insert([
            'sesion_caja_id' => (int) $payment->sesion_caja_id,
            'usuario_id' => (int) $payment->usuario_id,
            'tipo_movimiento' => $movementType,
            'monto' => Decimal::money((string) $payment->monto),
            'concepto' => $payment->tipo === 'devolucion'
                ? 'Devolucion derivada de pago'
                : 'Cobro derivado de pago',
            'ocurrido_en' => $payment->ocurrido_en,
            'created_at' => now(),
            'pago_venta_id' => $causalColumn === 'pago_venta_id' ? $paymentId : null,
            'pago_pedido_id' => $causalColumn === 'pago_pedido_id' ? $paymentId : null,
        ]);
    }
}
