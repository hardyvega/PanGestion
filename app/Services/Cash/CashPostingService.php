<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CashPostingService
{
    public function postSalePayment(int $paymentId): void
    {
        DB::transaction(fn () => $this->post('pagos_venta', 'pago_venta_id', $paymentId));
    }

    public function postOrderPayment(int $paymentId): void
    {
        DB::transaction(fn () => $this->post('pagos_pedido', 'pago_pedido_id', $paymentId));
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
            throw (new ModelNotFoundException)->setModel($paymentTable, [$paymentId]);
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

        $session = DB::table('sesiones_caja')->where('id', $payment->sesion_caja_id)->lockForUpdate()->first();
        if ($session === null) {
            throw (new ModelNotFoundException)->setModel('sesiones_caja', [$payment->sesion_caja_id]);
        }
        CashSessionGuard::assertOpen($session);
        if (($session->fecha_operativa ?? null) !== null && CommercialDate::fromInstant(CarbonImmutable::parse($payment->ocurrido_en)) !== $session->fecha_operativa) {
            throw new InventoryDomainException('El pago no corresponde a la fecha operativa de la sesión.');
        }
        if (! (bool) $payment->afecta_efectivo) {
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
