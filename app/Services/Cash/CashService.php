<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\MovimientoCaja;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CashService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions)
    {
    }

    public function open(User $actor, int $cashRegisterId, string $initialAmount): SesionCaja
    {
        return DB::transaction(function () use ($actor, $cashRegisterId, $initialAmount): SesionCaja {
            $this->permissions->authorize($actor, PermissionCode::CashSessionsOpen);
            $initialAmount = Decimal::money($initialAmount);

            if (Decimal::sign($initialAmount) < 0) {
                throw new InvalidArgumentException('El monto inicial no puede ser negativo.');
            }

            $cashRegister = DB::table('cajas')->where('id', $cashRegisterId)->lockForUpdate()->first();
            if ($cashRegister === null) {
                throw (new ModelNotFoundException())->setModel('cajas', [$cashRegisterId]);
            }
            if (! (bool) $cashRegister->activo) {
                throw new InventoryDomainException('La caja seleccionada no está activa.');
            }

            if (DB::table('sesiones_caja')->where('caja_id', $cashRegisterId)->whereNull('cerrada_en')->exists()) {
                throw new InventoryDomainException('La caja ya tiene una sesión abierta.');
            }

            $session = new SesionCaja();
            $session->forceFill([
                'caja_id' => $cashRegisterId,
                'usuario_apertura_id' => $actor->getKey(),
                'monto_inicial' => $initialAmount,
                'abierta_en' => now('UTC'),
            ])->save();

            return $session->fresh(['caja', 'usuarioApertura']);
        });
    }

    public function close(User $actor, int $sessionId, string $declaredAmount, ?string $observation = null): SesionCaja
    {
        return DB::transaction(function () use ($actor, $sessionId, $declaredAmount, $observation): SesionCaja {
            $this->permissions->authorize($actor, PermissionCode::CashSessionsClose);
            $declaredAmount = Decimal::money($declaredAmount);

            if (Decimal::sign($declaredAmount) < 0) {
                throw new InvalidArgumentException('El monto declarado no puede ser negativo.');
            }

            $session = SesionCaja::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw (new ModelNotFoundException())->setModel(SesionCaja::class, [$sessionId]);
            }
            if ($session->cerrada_en !== null) {
                throw new InventoryDomainException('La sesión de caja ya está cerrada.');
            }

            $observation = $observation === null ? null : trim($observation);
            if ($observation === '') {
                $observation = null;
            }

            $theoreticalAmount = $this->theoreticalAmountForLockedSession($session);
            $session->forceFill([
                'usuario_cierre_id' => $actor->getKey(),
                'cerrada_en' => now('UTC'),
                'monto_cierre_declarado' => $declaredAmount,
                'monto_teorico_cierre' => $theoreticalAmount,
                'observacion_cierre' => $observation,
            ])->save();

            return $session->fresh(['caja', 'usuarioApertura', 'usuarioCierre']);
        });
    }

    public function registerManualMovement(
        User $actor,
        int $sessionId,
        string $movementType,
        string $amount,
        string $concept,
    ): MovimientoCaja {
        return DB::transaction(function () use ($actor, $sessionId, $movementType, $amount, $concept): MovimientoCaja {
            $this->permissions->authorize($actor, PermissionCode::CashManualMovementsManage);
            $movementType = trim($movementType);
            if (! in_array($movementType, ['ingreso', 'egreso', 'retiro'], true)) {
                throw new InvalidArgumentException('El tipo de movimiento de caja no es válido.');
            }

            $amount = Decimal::money($amount);
            if (Decimal::sign($amount) <= 0) {
                throw new InvalidArgumentException('El monto del movimiento debe ser positivo.');
            }

            $concept = trim($concept);
            if ($concept === '') {
                throw new InvalidArgumentException('El movimiento requiere un concepto.');
            }

            $session = SesionCaja::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw (new ModelNotFoundException())->setModel(SesionCaja::class, [$sessionId]);
            }
            if ($session->cerrada_en !== null) {
                throw new InventoryDomainException('No se pueden registrar movimientos en una sesión cerrada.');
            }

            $movement = new MovimientoCaja();
            $movement->forceFill([
                'sesion_caja_id' => $sessionId,
                'usuario_id' => $actor->getKey(),
                'tipo_movimiento' => $movementType,
                'monto' => $amount,
                'concepto' => $concept,
                'ocurrido_en' => now('UTC'),
            ])->save();

            return $movement->fresh(['sesionCaja.caja', 'usuario']);
        });
    }

    public function activeRegisters(User $actor): Collection
    {
        $this->permissions->authorize($actor, PermissionCode::CashSessionsOpen);

        return DB::table('cajas')
            ->where('activo', true)
            ->orderBy('nombre')
            ->orderBy('id')
            ->get(['id', 'codigo', 'nombre', 'descripcion'])
            ->map(static fn (object $register): array => [
                'id' => (int) $register->id,
                'codigo' => (string) $register->codigo,
                'nombre' => (string) $register->nombre,
                'descripcion' => $register->descripcion,
            ]);
    }

    public function sessions(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::CashSessionsView);

        return SesionCaja::query()
            ->with(['caja', 'usuarioApertura', 'usuarioCierre'])
            ->orderByDesc('abierta_en')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'sessions_page')
            ->withQueryString();
    }

    public function openSessions(User $actor): Collection
    {
        $this->permissions->authorize($actor, PermissionCode::CashSessionsView);

        return SesionCaja::query()
            ->with('caja')
            ->whereNull('cerrada_en')
            ->orderByDesc('abierta_en')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array{session: SesionCaja, theoretical: string, difference: ?string, totals: array<string, string>, movements: Collection<int, array<string, mixed>>} */
    public function overview(User $actor, int $sessionId): array
    {
        $this->permissions->authorize($actor, PermissionCode::CashSessionsView);
        $session = SesionCaja::query()
            ->with(['caja', 'usuarioApertura', 'usuarioCierre'])
            ->whereKey($sessionId)
            ->first();

        if ($session === null) {
            throw (new ModelNotFoundException())->setModel(SesionCaja::class, [$sessionId]);
        }

        $theoretical = $this->theoreticalAmountForSession($session);
        $difference = $session->monto_cierre_declarado === null
            ? null
            : Decimal::subtract((string) $session->monto_cierre_declarado, $theoretical, 2);
        $movements = $this->movementRows($sessionId, null);

        return [
            'session' => $session,
            'theoretical' => $theoretical,
            'difference' => $difference,
            'totals' => $this->movementTotals($session, $movements),
            'movements' => $movements->take(12)->values(),
        ];
    }

    /** @param array<string, mixed> $filters */
    public function movements(User $actor, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::CashMovementsView);
        $query = $this->movementQuery();

        if (is_numeric($filters['session_id'] ?? null) && (int) $filters['session_id'] > 0) {
            $query->where('m.sesion_caja_id', (int) $filters['session_id']);
        }
        if (is_numeric($filters['caja_id'] ?? null) && (int) $filters['caja_id'] > 0) {
            $query->where('s.caja_id', (int) $filters['caja_id']);
        }
        if (is_numeric($filters['usuario_id'] ?? null) && (int) $filters['usuario_id'] > 0) {
            $query->where('m.usuario_id', (int) $filters['usuario_id']);
        }
        if (is_string($filters['tipo'] ?? null) && in_array($filters['tipo'], ['ingreso', 'egreso', 'retiro'], true)) {
            $query->where('m.tipo_movimiento', $filters['tipo']);
        }
        if (is_string($filters['origen'] ?? null)) {
            $this->applyOriginFilter($query, $filters['origen']);
        }
        if (is_string($filters['fecha_desde'] ?? null) && $filters['fecha_desde'] !== '') {
            $query->whereDate('m.ocurrido_en', '>=', $filters['fecha_desde']);
        }
        if (is_string($filters['fecha_hasta'] ?? null) && $filters['fecha_hasta'] !== '') {
            $query->whereDate('m.ocurrido_en', '<=', $filters['fecha_hasta']);
        }

        return $query
            ->orderByDesc('m.ocurrido_en')
            ->orderByDesc('m.id')
            ->paginate($perPage, ['*'], 'movements_page')
            ->through(fn (object $movement): array => $this->movementData($movement))
            ->withQueryString();
    }

    public function registersForFilter(User $actor): Collection
    {
        $this->permissions->authorize($actor, PermissionCode::CashMovementsView);

        return DB::table('cajas')->orderBy('nombre')->orderBy('id')->get(['id', 'nombre']);
    }

    public function usersForFilter(User $actor): Collection
    {
        $this->permissions->authorize($actor, PermissionCode::CashMovementsView);

        return DB::table('usuarios')
            ->where('activo', true)
            ->orderBy('nombre')
            ->orderBy('apellido')
            ->get(['id', 'nombre', 'apellido']);
    }

    private function theoreticalAmountForSession(SesionCaja $session): string
    {
        return $this->theoreticalAmountForLockedSession($session);
    }

    private function theoreticalAmountForLockedSession(SesionCaja $session): string
    {
        $amount = Decimal::money((string) $session->monto_inicial);
        $movements = DB::table('movimientos_caja')
            ->where('sesion_caja_id', $session->getKey())
            ->orderBy('id')
            ->get(['tipo_movimiento', 'monto']);

        foreach ($movements as $movement) {
            $amount = $movement->tipo_movimiento === 'ingreso'
                ? Decimal::add($amount, Decimal::money((string) $movement->monto), 2)
                : Decimal::subtract($amount, Decimal::money((string) $movement->monto), 2);
        }

        return $amount;
    }

    private function movementQuery(): mixed
    {
        return DB::table('movimientos_caja as m')
            ->join('sesiones_caja as s', 's.id', '=', 'm.sesion_caja_id')
            ->join('cajas as c', 'c.id', '=', 's.caja_id')
            ->join('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->leftJoin('pagos_venta as pv', 'pv.id', '=', 'm.pago_venta_id')
            ->leftJoin('pagos_pedido as pp', 'pp.id', '=', 'm.pago_pedido_id')
            ->select([
                'm.id', 'm.sesion_caja_id', 'm.usuario_id', 'm.tipo_movimiento', 'm.monto', 'm.concepto',
                'm.ocurrido_en', 'm.pago_venta_id', 'm.pago_pedido_id',
                's.caja_id', 's.cerrada_en', 'c.nombre as caja_nombre',
                'u.nombre as usuario_nombre', 'u.apellido as usuario_apellido',
                'pv.tipo as pago_venta_tipo', 'pp.tipo as pago_pedido_tipo',
            ]);
    }

    private function movementRows(int $sessionId, ?int $limit): Collection
    {
        $query = $this->movementQuery()->where('m.sesion_caja_id', $sessionId)
            ->orderByDesc('m.ocurrido_en')->orderByDesc('m.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn (object $movement): array => $this->movementData($movement));
    }

    /** @param mixed $query */
    private function applyOriginFilter(mixed $query, string $origin): void
    {
        if ($origin === 'manual') {
            $query->whereNull('m.pago_venta_id')->whereNull('m.pago_pedido_id');
        } elseif ($origin === 'venta') {
            $query->whereNotNull('m.pago_venta_id');
        } elseif ($origin === 'pedido') {
            $query->whereNotNull('m.pago_pedido_id');
        } elseif ($origin === 'devolucion') {
            $query->where(function ($nested): void {
                $nested->where('pv.tipo', 'devolucion')->orWhere('pp.tipo', 'devolucion');
            });
        }
    }

    /** @return array<string, mixed> */
    private function movementData(object $movement): array
    {
        $isSale = $movement->pago_venta_id !== null;
        $isOrder = $movement->pago_pedido_id !== null;
        $paymentType = $isSale ? $movement->pago_venta_tipo : ($isOrder ? $movement->pago_pedido_tipo : null);

        return [
            'id' => (int) $movement->id,
            'session_id' => (int) $movement->sesion_caja_id,
            'cash_register_id' => (int) $movement->caja_id,
            'cash_register_name' => (string) $movement->caja_nombre,
            'user_id' => (int) $movement->usuario_id,
            'user_name' => trim($movement->usuario_nombre.' '.$movement->usuario_apellido),
            'type' => (string) $movement->tipo_movimiento,
            'amount' => Decimal::money((string) $movement->monto),
            'concept' => (string) $movement->concepto,
            'occurred_at' => $movement->ocurrido_en,
            'source' => $isSale ? 'venta' : ($isOrder ? 'pedido' : 'manual'),
            'source_label' => $isSale ? 'Venta #'.$movement->pago_venta_id : ($isOrder ? 'Pedido #'.$movement->pago_pedido_id : 'Manual'),
            'payment_type' => $paymentType,
            'is_refund' => $paymentType === 'devolucion',
            'causal_id' => $isSale ? (int) $movement->pago_venta_id : ($isOrder ? (int) $movement->pago_pedido_id : null),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $movements */
    private function movementTotals(SesionCaja $session, Collection $movements): array
    {
        $totals = [
            'cash_collections' => '0.00',
            'cash_refunds' => '0.00',
            'manual_income' => '0.00',
            'manual_expense' => '0.00',
            'withdrawals' => '0.00',
        ];

        $allMovements = $this->movementRows((int) $session->getKey(), null);
        foreach ($allMovements as $movement) {
            if ($movement['source'] !== 'manual') {
                $key = $movement['is_refund'] ? 'cash_refunds' : 'cash_collections';
            } elseif ($movement['type'] === 'ingreso') {
                $key = 'manual_income';
            } elseif ($movement['type'] === 'retiro') {
                $key = 'withdrawals';
            } else {
                $key = 'manual_expense';
            }
            $totals[$key] = Decimal::add($totals[$key], (string) $movement['amount'], 2);
        }

        return $totals;
    }
}
