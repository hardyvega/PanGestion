<?php

declare(strict_types=1);

namespace App\Services\Cash;

use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\CierreCaja;
use App\Models\FondoCajaAutorizado;
use App\Models\MovimientoCaja;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CashService
{
    public function __construct(private readonly EffectivePermissionResolver $permissions, private readonly CashFundService $funds) {}

    public function canAccess(User $actor): bool
    {
        return collect([
            PermissionCode::CashSessionsView,
            PermissionCode::CashSessionsOpen,
            PermissionCode::CashSessionsClose,
            PermissionCode::CashMovementsView,
            PermissionCode::CashManualMovementsManage,
            PermissionCode::CashFundsAuthorize,
        ])->contains(fn (PermissionCode $permission): bool => $this->permissions->allows($actor, $permission));
    }

    public function open(User $actor, int $cashRegisterId, int $fundId, string $date, string $uuid): SesionCaja
    {
        $this->permissions->authorize($actor, PermissionCode::CashSessionsOpen);
        $this->funds->validateDate($date);
        if (! Str::isUuid($uuid)) {
            throw new InvalidArgumentException('La confirmación de apertura no es válida.');
        }

        return DB::transaction(function () use ($actor, $cashRegisterId, $fundId, $date, $uuid): SesionCaja {
            $this->permissions->authorize($actor, PermissionCode::CashSessionsOpen);

            $cashRegister = DB::table('cajas')->where('id', $cashRegisterId)->lockForUpdate()->first();
            if ($cashRegister === null) {
                throw (new ModelNotFoundException)->setModel('cajas', [$cashRegisterId]);
            }
            if (! (bool) $cashRegister->activo) {
                throw new InventoryDomainException('La caja seleccionada no está activa.');
            }

            $retry = SesionCaja::query()->where('clave_idempotencia_apertura', $uuid)->first();
            if ($retry !== null) {
                if ((int) $retry->caja_id !== $cashRegisterId || (int) $retry->usuario_apertura_id !== (int) $actor->id || (int) $retry->fondo_autorizado_id !== $fundId || $retry->fecha_operativa?->format('Y-m-d') !== $date) {
                    throw new IdempotencyConflictException('La confirmación ya corresponde a otra apertura.');
                }

                return $retry;
            }
            if ($date !== CommercialDate::today()) {
                throw new InventoryDomainException('La autorización no corresponde a la fecha operativa actual.');
            }
            $fund = FondoCajaAutorizado::query()->whereKey($fundId)->lockForUpdate()->first();
            if ($fund === null || (int) $fund->caja_id !== $cashRegisterId || $fund->fecha_operativa->format('Y-m-d') !== $date || $fund->utilizada_en !== null) {
                throw new InventoryDomainException('Falta un fondo autorizado vigente y sin utilizar para esta caja y fecha.');
            }

            if (DB::table('sesiones_caja')->where('caja_id', $cashRegisterId)->whereNull('cerrada_en')->exists()) {
                throw new InventoryDomainException('La caja ya tiene una sesión abierta.');
            }

            $session = new SesionCaja;
            $session->forceFill([
                'caja_id' => $cashRegisterId,
                'usuario_apertura_id' => $actor->getKey(),
                'monto_inicial' => (string) $fund->monto,
                'fondo_autorizado_id' => $fund->id,
                'fecha_operativa' => $date,
                'clave_idempotencia_apertura' => $uuid,
                'abierta_en' => now('UTC'),
            ])->save();
            DB::table('fondos_caja_autorizados')->where('id', $fundId)->update(['utilizada_en' => now('UTC')]);

            return $session->fresh(['caja', 'usuarioApertura']);
        });
    }

    public function close(User $actor, int $sessionId, string $declaredAmount, string $nextFund, string $nextDate, string $uuid, bool $withdrawalConfirmed, ?string $observation = null, ?string $expectedReviewed = null): SesionCaja
    {
        $this->permissions->authorizeAll($actor, [PermissionCode::CashSessionsClose, PermissionCode::CashFundsAuthorize]);
        $declaredAmount = Decimal::money($declaredAmount);
        $nextFund = Decimal::money($nextFund);
        $this->funds->validateDate($nextDate);
        $observation = $observation === null ? null : trim($observation);
        if (! Str::isUuid($uuid) || Decimal::sign($declaredAmount) < 0 || Decimal::sign($nextFund) < 0 || Decimal::compare($nextFund, $declaredAmount, 2) > 0 || mb_strlen($observation ?? '') > 5000) {
            throw new InvalidArgumentException('El fondo siguiente debe ser explícito y no superar el efectivo contado disponible.');
        }

        return DB::transaction(function () use ($actor, $sessionId, $declaredAmount, $nextFund, $nextDate, $uuid, $withdrawalConfirmed, $observation, $expectedReviewed): SesionCaja {
            $registerId = DB::table('sesiones_caja')->where('id', $sessionId)->value('caja_id');
            DB::table('cajas')->where('id', $registerId)->lockForUpdate()->first();
            $session = SesionCaja::query()->whereKey($sessionId)->lockForUpdate()->first();
            if ($session === null) {
                throw (new ModelNotFoundException)->setModel(SesionCaja::class, [$sessionId]);
            }
            $retry = CierreCaja::query()->where('clave_idempotencia', $uuid)->first();
            if ($retry !== null) {
                if ((int) $retry->sesion_caja_id !== $sessionId || (int) $retry->usuario_confirmador_id !== (int) $actor->id || (string) $retry->contado_antes_retiro !== $declaredAmount || (string) $retry->fondo_remanente !== $nextFund || $retry->fecha_siguiente->format('Y-m-d') !== $nextDate || ($retry->observacion ?? '') !== ($observation ?? '') || ! $withdrawalConfirmed) {
                    throw new IdempotencyConflictException('La confirmación ya corresponde a otro cierre.');
                }

                return $session->fresh(['cierre']);
            }
            $now = now('UTC');
            $today = CommercialDate::fromInstant($now);
            CashSessionGuard::assertClosable($session, $today);
            $date = $session->fecha_operativa->format('Y-m-d');
            if ($nextDate <= $date) {
                throw new InventoryDomainException('La próxima fecha operativa debe ser posterior a la jornada que se cierra.');
            }
            if ($nextDate < $today) {
                throw new InventoryDomainException('No se puede autorizar un fondo para una fecha vencida.');
            }
            if (! $withdrawalConfirmed) {
                throw new InventoryDomainException('Debes confirmar personalmente el contado, el retiro final y el cierre definitivo.');
            }
            if (DB::table('fondos_caja_autorizados')->where('caja_id', $registerId)->where('fecha_operativa', $nextDate)->exists()) {
                throw new InventoryDomainException('La fecha siguiente ya tiene un fondo autorizado. No se reemplazará.');
            }
            $theoreticalAmount = $this->theoreticalAmountForLockedSession($session);
            if ($expectedReviewed !== null && Decimal::money($expectedReviewed) !== $theoreticalAmount) {
                throw new InventoryDomainException('El saldo cambió mientras revisabas la caja. Revisa nuevamente la cuadratura antes de confirmar.');
            }
            $difference = Decimal::subtract($declaredAmount, $theoreticalAmount, 2);
            if (! Decimal::isZero($difference) && ($observation === null || $observation === '')) {
                throw new InventoryDomainException('Debes explicar el faltante o sobrante antes de confirmar el cierre.');
            }
            $withdrawal = Decimal::subtract($declaredAmount, $nextFund, 2);
            $summary = $this->closingSummary($session);
            $summary['opening'] = ['user_id' => $session->usuario_apertura_id, 'user_name' => trim($session->usuarioApertura?->nombre.' '.$session->usuarioApertura?->apellido), 'opened_at' => $session->abierta_en->toIso8601String(), 'cash_register_name' => $session->caja?->nombre, 'initial_fund' => (string) $session->monto_inicial];
            $summary['confirmer'] = ['id' => $actor->id, 'name' => trim($actor->nombre.' '.$actor->apellido)];
            $closingId = DB::table('cierres_caja')->insertGetId([
                'sesion_caja_id' => $sessionId, 'usuario_confirmador_id' => $actor->id,
                'clave_idempotencia' => $uuid, 'fecha_operativa' => $date, 'fecha_siguiente' => $nextDate,
                'esperado_antes_retiro' => $theoreticalAmount, 'contado_antes_retiro' => $declaredAmount,
                'diferencia' => $difference, 'retiro_final' => $withdrawal, 'fondo_remanente' => $nextFund,
                'observacion' => $observation === '' ? null : $observation, 'resumen' => json_encode($summary, JSON_THROW_ON_ERROR),
                'confirmado_en' => $now, 'created_at' => $now,
            ]);
            if (! Decimal::isZero($withdrawal)) {
                DB::table('movimientos_caja')->insert([
                    'sesion_caja_id' => $sessionId, 'usuario_id' => $actor->id, 'tipo_movimiento' => 'retiro',
                    'monto' => $withdrawal, 'concepto' => 'Retiro final confirmado al cerrar caja',
                    'ocurrido_en' => $now, 'created_at' => $now, 'cierre_caja_id' => $closingId,
                ]);
            }
            DB::table('fondos_caja_autorizados')->insert([
                'caja_id' => $registerId, 'fecha_operativa' => $nextDate, 'monto' => $nextFund,
                'usuario_autorizante_id' => $actor->id, 'origen' => 'cierre', 'cierre_origen_id' => $closingId,
                'clave_idempotencia' => $uuid, 'motivo' => 'Fondo autorizado en cierre definitivo', 'created_at' => $now,
            ]);
            $session->forceFill([
                'usuario_cierre_id' => $actor->getKey(),
                'cerrada_en' => $now,
                'monto_cierre_declarado' => $declaredAmount,
                'monto_teorico_cierre' => $theoreticalAmount,
                'observacion_cierre' => $observation === '' ? null : $observation,
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
                throw (new ModelNotFoundException)->setModel(SesionCaja::class, [$sessionId]);
            }
            if ($session->cerrada_en !== null) {
                throw new InventoryDomainException('No se pueden registrar movimientos en una sesión cerrada.');
            }
            CashSessionGuard::assertOpen($session);

            $movement = new MovimientoCaja;
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

        $today = CommercialDate::today();

        return DB::table('cajas')
            ->leftJoin('sesiones_caja as s', fn ($join) => $join->on('s.caja_id', '=', 'cajas.id')->whereNull('s.cerrada_en'))
            ->where('cajas.activo', true)
            ->orderBy('cajas.nombre')
            ->orderBy('cajas.id')
            ->get(['cajas.id', 'cajas.codigo', 'cajas.nombre', 'cajas.descripcion', 's.id as open_session_id', 's.fecha_operativa as open_session_date'])
            ->map(fn (object $register): array => [
                'id' => (int) $register->id,
                'codigo' => (string) $register->codigo,
                'nombre' => (string) $register->nombre,
                'descripcion' => $register->descripcion,
                'has_open_session' => $register->open_session_id !== null,
                'requires_review' => $register->open_session_id !== null && $register->open_session_date !== $today,
                'fund' => $register->open_session_id === null ? $this->funds->available((int) $register->id) : null,
            ]);
    }

    public function sessions(User $actor, int $perPage = 12): LengthAwarePaginator
    {
        $this->permissions->authorize($actor, PermissionCode::CashSessionsView);

        return SesionCaja::query()
            ->with(['caja', 'usuarioApertura', 'usuarioCierre', 'cierre'])
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
            throw (new ModelNotFoundException)->setModel(SesionCaja::class, [$sessionId]);
        }

        $theoretical = $session->cerrada_en !== null ? (string) $session->monto_teorico_cierre : $this->theoreticalAmountForSession($session);
        $difference = $session->monto_cierre_declarado === null
            ? null
            : Decimal::subtract((string) $session->monto_cierre_declarado, $theoretical, 2);
        $closing = $session->cierre;
        $movements = $closing === null ? $this->movementRows($sessionId, null) : collect($closing->resumen['movements']);
        if ($closing !== null && ! Decimal::isZero((string) $closing->retiro_final)) {
            $movements->prepend([
                'id' => $closing->movimientoRetiro?->id, 'type' => 'retiro',
                'amount' => (string) $closing->retiro_final, 'occurred_at' => $closing->confirmado_en->toIso8601String(),
                'concept' => 'Retiro final confirmado al cerrar caja', 'source_label' => 'Cierre definitivo',
                'user_id' => $closing->usuario_confirmador_id, 'user_name' => $closing->resumen['confirmer']['name'],
                'source' => 'cierre', 'is_refund' => false,
            ]);
        }

        return [
            'session' => $session,
            'theoretical' => $theoretical,
            'difference' => $difference,
            'totals' => $session->cierre?->resumen['totals'] ?? $this->movementTotals($session, $movements),
            'movements' => $movements->take(12)->values(),
            'closing' => $session->cierre,
            'summary' => $session->cierre?->resumen ?? $this->closingSummary($session),
        ];
    }

    public function actionableSessions(User $actor): Collection
    {
        $canManage = $this->permissions->allows($actor, PermissionCode::CashManualMovementsManage);
        $canClose = $this->permissions->allowsAll($actor, [PermissionCode::CashSessionsClose, PermissionCode::CashFundsAuthorize]);
        if (! $canManage && ! $canClose) {
            throw new AuthorizationException;
        }

        $query = SesionCaja::query()->with(['caja', 'usuarioApertura'])->whereNull('cerrada_en');

        return ($canClose
            ? $query->whereNotNull('fondo_autorizado_id')->where('fecha_operativa', '<=', CommercialDate::today())
            : $query->where('fecha_operativa', CommercialDate::today()))
            ->orderByDesc('abierta_en')->orderByDesc('id')->get();
    }

    public function closingOverview(User $actor, int $sessionId): array
    {
        $this->permissions->authorizeAll($actor, [PermissionCode::CashSessionsClose, PermissionCode::CashFundsAuthorize]);
        $session = SesionCaja::query()->with(['caja', 'usuarioApertura'])->whereNull('cerrada_en')
            ->whereNotNull('fondo_autorizado_id')->where('fecha_operativa', '<=', CommercialDate::today())->findOrFail($sessionId);
        $movements = $this->movementRows($sessionId, null);

        return ['session' => $session, 'theoretical' => $this->theoreticalAmountForLockedSession($session), 'difference' => null, 'totals' => $this->movementTotals($session, $movements), 'movements' => $movements->take(12), 'closing' => null, 'summary' => $this->closingSummary($session)];
    }

    private function closingSummary(SesionCaja $session): array
    {
        $payments = [];
        $byMethod = [];
        foreach (['pagos_venta' => 'venta', 'pagos_pedido' => 'pedido'] as $table => $source) {
            $operationColumn = $source === 'venta' ? 'venta_id' : 'pedido_id';
            $rows = DB::table($table.' as p')->join('metodos_pago as m', 'm.id', '=', 'p.metodo_pago_id')
                ->join('usuarios as u', 'u.id', '=', 'p.usuario_id')
                ->where('p.sesion_caja_id', $session->id)->orderBy('p.id')->get(['p.id', 'p.'.$operationColumn.' as operation_id', 'p.usuario_id', 'p.tipo', 'p.monto', 'p.ocurrido_en', 'm.codigo', 'm.nombre', 'u.nombre as user_first_name', 'u.apellido as user_last_name']);
            foreach ($rows as $row) {
                $amount = Decimal::money((string) $row->monto);
                $byMethod[$row->codigo] ??= ['name' => $row->nombre, 'charges' => '0.00', 'refunds' => '0.00', 'net' => '0.00'];
                $key = $row->tipo === 'devolucion' ? 'refunds' : 'charges';
                $byMethod[$row->codigo][$key] = Decimal::add($byMethod[$row->codigo][$key], $amount, 2);
                $byMethod[$row->codigo]['net'] = Decimal::subtract($byMethod[$row->codigo]['charges'], $byMethod[$row->codigo]['refunds'], 2);
                $payments[] = ['source' => $source, 'id' => $row->id, 'operation_id' => $row->operation_id, 'user_id' => $row->usuario_id, 'user_name' => trim($row->user_first_name.' '.$row->user_last_name), 'type' => $row->tipo, 'method' => $row->nombre, 'amount' => $amount, 'occurred_at' => $row->ocurrido_en];
            }
        }
        $movements = $this->movementRows((int) $session->id, null);
        $sales = DB::table('ventas')->where('sesion_caja_id', $session->id)->orderBy('id')->get(['id', 'usuario_id', 'estado', 'total', 'fecha_comercial'])->map(fn ($s) => (array) $s)->all();
        $salesTotal = '0.00';
        foreach ($sales as $sale) {
            if ($sale['estado'] === 'completada') {
                $salesTotal = Decimal::add($salesTotal, (string) $sale['total'], 2);
            }
        }

        return ['version' => 1, 'totals' => $this->movementTotals($session, $movements), 'methods' => $byMethod, 'payments' => $payments, 'movements' => $movements->all(), 'sales' => $sales, 'sales_total' => $salesTotal];
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
            $query->where('m.ocurrido_en', '>=', CommercialDate::utcRange($filters['fecha_desde'])['start']);
        }
        if (is_string($filters['fecha_hasta'] ?? null) && $filters['fecha_hasta'] !== '') {
            $query->where('m.ocurrido_en', '<', CommercialDate::utcRange($filters['fecha_hasta'])['end']);
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
                'm.cierre_caja_id',
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

    private function applyOriginFilter(mixed $query, string $origin): void
    {
        if ($origin === 'manual') {
            $query->whereNull('m.pago_venta_id')->whereNull('m.pago_pedido_id')->whereNull('m.cierre_caja_id');
        } elseif ($origin === 'cierre') {
            $query->whereNotNull('m.cierre_caja_id');
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
        $isClosing = $movement->cierre_caja_id !== null;
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
            'source' => $isSale ? 'venta' : ($isOrder ? 'pedido' : ($isClosing ? 'cierre' : 'manual')),
            'source_label' => $isSale ? 'Venta #'.$movement->pago_venta_id : ($isOrder ? 'Pedido #'.$movement->pago_pedido_id : ($isClosing ? 'Cierre definitivo #'.$movement->cierre_caja_id : 'Manual')),
            'payment_type' => $paymentType,
            'is_refund' => $paymentType === 'devolucion',
            'causal_id' => $isSale ? (int) $movement->pago_venta_id : ($isOrder ? (int) $movement->pago_pedido_id : ($isClosing ? (int) $movement->cierre_caja_id : null)),
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

        foreach ($movements as $movement) {
            if (in_array($movement['source'], ['venta', 'pedido'], true)) {
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
