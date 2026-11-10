<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Inventory\InventoryStockMutation;
use App\Data\Sales\AnnulSaleCommand;
use App\Data\Sales\CompleteSaleCommand;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentType;
use App\Enums\SaleStatus;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Exceptions\Inventory\InventoryMovementAlreadyReversedException;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Models\Venta;
use App\Services\Cash\CashPostingService;
use App\Services\Cash\CashSessionGuard;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\StockDailyRebuilder;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class SaleExecutionService
{
    public function __construct(
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stock,
        private readonly StockDailyRebuilder $dailyStock,
        private readonly SaleCorrelativeAllocator $correlatives,
        private readonly CashPostingService $cashPosting,
    ) {
    }

    public function complete(User $actor, CompleteSaleCommand $command): Venta
    {
        $existing = Venta::query()
            ->where('clave_idempotencia', $command->commandUuid)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->assertCompatibleCompletion($existing, $actor, $command);

            return $existing;
        }

        $this->assertPaymentUuidAvailability($command);
        $session = $this->lockCashSession($command->cashSessionId, true);
        $this->assertClientExists($command->clientId);
        $this->validateChargePayments($command);
        $completedAt = $this->completeInstant($command);
        $commercialDate = CommercialDate::fromInstant($completedAt);
        $number = $this->correlatives->next($commercialDate);
        $articles = $this->lockArticlesForLines($command);
        $units = $this->loadUnits($articles);

        $total = '0.00';
        $lineSubtotals = [];

        foreach ($command->lines as $line) {
            $subtotal = Decimal::multiply($line->quantity, $line->unitPrice, 2);
            $lineSubtotals[] = $subtotal;
            $total = Decimal::add($total, $subtotal, 2);
        }

        $saleId = (int) DB::table('ventas')->insertGetId([
            'fecha_comercial' => $commercialDate,
            'numero_diario' => $number,
            'clave_idempotencia' => $command->commandUuid,
            'usuario_id' => (int) $actor->getKey(),
            'sesion_caja_id' => (int) $session->id,
            'cliente_id' => $command->clientId,
            'pedido_id' => null,
            'estado' => SaleStatus::Completed->value,
            'total' => $total,
            'monto_anticipo_aplicado' => '0.00',
            'completada_en' => $completedAt->setTimezone('UTC'),
            'observacion' => $command->observation,
        ]);

        $detailIds = [];

        foreach ($command->lines as $index => $line) {
            $article = $articles->get($line->articleId);
            $unit = $units[$article->unidad_id];
            $detailIds[$index] = (int) DB::table('detalle_ventas')->insertGetId([
                'venta_id' => $saleId,
                'articulo_id' => $line->articleId,
                'articulo_sku_snapshot' => $article->sku,
                'articulo_nombre_snapshot' => $article->nombre,
                'unidad_codigo_snapshot' => $unit->codigo,
                'unidad_simbolo_snapshot' => $unit->simbolo,
                'cantidad' => $line->quantity,
                'precio_unitario' => $line->unitPrice,
                'subtotal' => $lineSubtotals[$index],
            ]);
        }

        foreach ($command->payments as $payment) {
            $paymentId = (int) DB::table('pagos_venta')->insertGetId([
                'venta_id' => $saleId,
                'metodo_pago_id' => $payment->paymentMethodId,
                'sesion_caja_id' => $payment->cashSessionId,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $payment->commandUuid,
                'tipo' => PaymentType::Charge->value,
                'monto' => $payment->amount,
                'monto_recibido' => $payment->receivedAmount,
                'vuelto' => $payment->change,
                'ocurrido_en' => $completedAt->setTimezone('UTC'),
                'observacion' => $payment->observation,
            ]);
            $this->cashPosting->postSalePayment($paymentId);
        }

        $mutations = [];
        $positions = [];

        foreach ($command->lines as $index => $line) {
            $article = $articles->get($line->articleId);
            $position = ($positions[$line->articleId] ?? 0) + 1;
            $positions[$line->articleId] = $position;
            $unit = $units[$article->unidad_id];
            $mutations[] = new InventoryStockMutation(
                articleId: $line->articleId,
                type: InventoryMovementType::Sale,
                delta: '-'.$line->quantity,
                position: $position,
                occurredAt: $completedAt,
                actorId: (int) $actor->getKey(),
                authorizerId: null,
                reason: null,
                unitCode: $unit->codigo,
                unitSymbol: $unit->simbolo,
                saleDetailId: $detailIds[$index],
                receiptDetailId: null,
                productionDetailId: null,
                productionConsumptionId: null,
                wasteId: null,
                inventoryCountDetailId: null,
                reversedMovementId: null,
            );
        }

        $simulation = $this->stock->simulatePlan($articles, $command->commandUuid, $mutations);
        $this->stock->applyPlan($simulation);
        $this->rebuildDailyStock($articles->ids(), $commercialDate);

        return Venta::query()->findOrFail($saleId);
    }

    public function annul(User $actor, AnnulSaleCommand $command): Venta
    {
        $sale = Venta::query()
            ->whereKey($command->saleId)
            ->lockForUpdate()
            ->first();

        if ($sale === null) {
            throw (new ModelNotFoundException())->setModel(Venta::class, [$command->saleId]);
        }

        if ($sale->estado === SaleStatus::Annulled) {
            if ($sale->clave_idempotencia_anulacion !== $command->commandUuid) {
                throw new InventoryMovementAlreadyReversedException('La venta ya fue anulada con otro comando.');
            }

            if ((int) $sale->usuario_anulador_id !== (int) $actor->getKey()
                || $sale->motivo_anulacion !== $command->reason) {
                throw new IdempotencyConflictException('El UUID de anulacion ya fue usado con otra intencion.');
            }

            return $sale;
        }

        if ($sale->estado !== SaleStatus::Completed) {
            throw new InventoryDomainException('Solo se pueden anular ventas completadas.');
        }

        if ($sale->pedido_id !== null && $sale->pedido?->isFree()) {
            throw new InventoryDomainException('La venta de un pedido por encargo todavía no se puede anular desde el sistema.');
        }

        $this->lockCashSession((int) $sale->sesion_caja_id, true);
        $details = DB::table('detalle_ventas')
            ->where('venta_id', $sale->getKey())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($details->isEmpty()) {
            throw new InventoryDomainException('La venta no tiene detalles para anular.');
        }

        $articleIds = $details->pluck('articulo_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $articles = $this->lockManager->lockArticles($articleIds);
        $movements = MovimientoInventario::query()
            ->where('tipo_movimiento', InventoryMovementType::Sale->value)
            ->whereIn('detalle_venta_id', $details->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $movementsByDetail = $movements->keyBy('detalle_venta_id');

        if ($movements->count() !== $details->count()) {
            throw new InventoryDomainException('La venta no tiene un movimiento original por detalle.');
        }

        foreach ($details as $detail) {
            if (! $movementsByDetail->has($detail->id)) {
                throw new InventoryDomainException('La venta no tiene una causal de inventario completa.');
            }
        }

        $refund = $this->prepareRefund($sale, $actor, $command);
        if ($refund['payment_table'] === 'pagos_venta') {
            $this->cashPosting->postSalePayment($refund['payment_id']);
        } else {
            $this->cashPosting->postOrderPayment($refund['payment_id']);
        }
        $mutations = [];
        $positions = [];

        foreach ($details as $detail) {
            /** @var MovimientoInventario $original */
            $original = $movementsByDetail->get($detail->id);
            $articleId = (int) $detail->articulo_id;
            $article = $articles->get($articleId);
            $position = ($positions[$articleId] ?? 0) + 1;
            $positions[$articleId] = $position;
            $unitCode = (string) $detail->unidad_codigo_snapshot;
            $unitSymbol = (string) $detail->unidad_simbolo_snapshot;
            $mutations[] = new InventoryStockMutation(
                articleId: $articleId,
                type: InventoryMovementType::SaleReversal,
                delta: Decimal::subtract('0.000', (string) $original->cantidad_delta, 3),
                position: $position,
                occurredAt: $refund['occurred_at'],
                actorId: (int) $actor->getKey(),
                authorizerId: null,
                reason: null,
                unitCode: $unitCode,
                unitSymbol: $unitSymbol,
                saleDetailId: (int) $detail->id,
                receiptDetailId: null,
                productionDetailId: null,
                productionConsumptionId: null,
                wasteId: null,
                inventoryCountDetailId: null,
                reversedMovementId: (int) $original->getKey(),
            );
        }

        $simulation = $this->stock->simulatePlan($articles, $command->commandUuid, $mutations);
        $this->stock->applyPlan($simulation);
        $commercialDate = (string) $sale->fecha_comercial->format('Y-m-d');
        $this->rebuildDailyStock($articles->ids(), $commercialDate);

        DB::table('ventas')
            ->where('id', $sale->getKey())
            ->update([
                'estado' => SaleStatus::Annulled->value,
                'anulada_en' => $refund['occurred_at']->setTimezone('UTC'),
                'usuario_anulador_id' => (int) $actor->getKey(),
                'motivo_anulacion' => $command->reason,
                'clave_idempotencia_anulacion' => $command->commandUuid,
            ]);

        return Venta::query()->findOrFail($sale->getKey());
    }

    private function completeInstant(CompleteSaleCommand $command): CarbonImmutable
    {
        return $command->completedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($command->completedAt)->setTimezone('UTC');
    }

    private function lockCashSession(int $sessionId, bool $mustBeOpen): object
    {
        $session = DB::table('sesiones_caja')
            ->where('id', $sessionId)
            ->lockForUpdate()
            ->first();

        if ($session === null) {
            throw (new ModelNotFoundException)->setModel('sesiones_caja', [$sessionId]);
        }

        if ($mustBeOpen) {
            CashSessionGuard::assertOpen($session);
        }

        return $session;
    }

    private function assertClientExists(?int $clientId): void
    {
        if ($clientId !== null && ! DB::table('clientes')->where('id', $clientId)->exists()) {
            throw (new ModelNotFoundException())->setModel('clientes', [$clientId]);
        }
    }

    private function validateChargePayments(CompleteSaleCommand $command): void
    {
        $methodIds = [];

        foreach ($command->payments as $payment) {
            if ($payment->type !== PaymentType::Charge) {
                throw new InventoryDomainException('Una venta solo admite pagos de cobro.');
            }

            if ($payment->cashSessionId !== $command->cashSessionId) {
                throw new InventoryDomainException('Todos los pagos deben pertenecer a la sesion de la venta.');
            }

            $methodIds[$payment->paymentMethodId] = true;
        }

        $methods = DB::table('metodos_pago')
            ->whereIn('id', array_keys($methodIds))
            ->get()
            ->keyBy('id');

        foreach (array_keys($methodIds) as $methodId) {
            $method = $methods->get($methodId);

            if ($method === null) {
                throw (new ModelNotFoundException())->setModel('metodos_pago', [$methodId]);
            }

            if (! (bool) $method->activo) {
                throw new InventoryDomainException('El metodo de pago debe estar activo.');
            }
        }

    }

    private function lockArticlesForLines(CompleteSaleCommand $command): \App\Services\Inventory\LockedArticleSet
    {
        $ids = array_map(static fn ($line): int => $line->articleId, $command->lines);
        $articles = $this->lockManager->lockArticles($ids);

        foreach ($articles as $article) {
            if (! (bool) $article->activo
                || ! in_array($article->tipo_articulo, ['elaborado', 'reventa'], true)) {
                throw new InventoryDomainException('El articulo no esta habilitado para la venta.');
            }
        }

        return $articles;
    }

    private function loadUnits(\App\Services\Inventory\LockedArticleSet $articles): array
    {
        $unitIds = [];

        foreach ($articles as $article) {
            $unitIds[(int) $article->unidad_id] = true;
        }

        $units = DB::table('unidades_medida')
            ->whereIn('id', array_keys($unitIds))
            ->get()
            ->keyBy('id')
            ->all();

        foreach (array_keys($unitIds) as $unitId) {
            if (! isset($units[$unitId])) {
                throw (new ModelNotFoundException())->setModel('unidades_medida', [$unitId]);
            }
        }

        return $units;
    }

    private function assertPaymentUuidAvailability(CompleteSaleCommand $command): void
    {
        $uuids = array_map(static fn ($payment): string => $payment->commandUuid, $command->payments);
        $existing = DB::table('pagos_venta')
            ->whereIn('clave_idempotencia', $uuids)
            ->exists();

        if ($existing) {
            throw new IdempotencyConflictException('Un UUID de pago ya fue utilizado por otra operacion.');
        }
    }

    private function assertCompatibleCompletion(
        Venta $sale,
        User $actor,
        CompleteSaleCommand $command,
    ): void {
        if ((int) $sale->usuario_id !== (int) $actor->getKey()
            || (int) $sale->sesion_caja_id !== $command->cashSessionId
            || $sale->cliente_id !== $command->clientId
            || $sale->observacion !== $command->observation) {
            throw new IdempotencyConflictException('El UUID de venta ya fue usado con otra intencion.');
        }

        $details = DB::table('detalle_ventas')
            ->where('venta_id', $sale->getKey())
            ->orderBy('id')
            ->get();

        if ($details->count() !== count($command->lines)) {
            throw new IdempotencyConflictException('El UUID de venta ya tiene otras lineas.');
        }

        foreach ($command->lines as $index => $line) {
            $detail = $details[$index];

            if ((int) $detail->articulo_id !== $line->articleId
                || (string) $detail->cantidad !== $line->quantity
                || (string) $detail->precio_unitario !== $line->unitPrice) {
                throw new IdempotencyConflictException('El UUID de venta ya fue usado con otras cantidades o precios.');
            }
        }

        $payments = DB::table('pagos_venta')
            ->where('venta_id', $sale->getKey())
            ->orderBy('id')
            ->get();

        if ($payments->count() !== count($command->payments)) {
            throw new IdempotencyConflictException('El UUID de venta ya tiene otros pagos.');
        }

        foreach ($command->payments as $index => $payment) {
            $stored = $payments[$index];

            if ((int) $stored->metodo_pago_id !== $payment->paymentMethodId
                || (int) $stored->sesion_caja_id !== $payment->cashSessionId
                || (string) $stored->clave_idempotencia !== $payment->commandUuid
                || (string) $stored->tipo !== $payment->type->value
                || (string) $stored->monto !== $payment->amount
                || $this->nullableDecimal($stored->monto_recibido, 2) !== $payment->receivedAmount
                || $this->nullableDecimal($stored->vuelto, 2) !== $payment->change
                || $stored->observacion !== $payment->observation) {
                throw new IdempotencyConflictException('El UUID de venta ya fue usado con otros pagos.');
            }
        }
    }

    private function prepareRefund(Venta $sale, User $actor, AnnulSaleCommand $command): array
    {
        $derived = $sale->pedido_id !== null;
        $table = $derived ? 'pagos_pedido' : 'pagos_venta';
        $foreignKey = $derived ? 'pedido_id' : 'venta_id';
        $foreignId = $derived ? (int) $sale->pedido_id : (int) $sale->getKey();
        $existing = DB::table($table)
            ->where('clave_idempotencia', $command->commandUuid)
            ->first();

        if ($existing !== null) {
            throw new IdempotencyConflictException('El UUID de anulacion ya fue usado por otro pago.');
        }

        $payments = DB::table($table)
            ->where($foreignKey, $foreignId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $charges = $payments->where('tipo', PaymentType::Charge->value);
        $refunds = $payments->where('tipo', PaymentType::Refund->value);

        if ($charges->count() !== 1 || $refunds->isNotEmpty()) {
            throw new InventoryDomainException('La politica de devolucion requiere un unico cobro sin devoluciones previas.');
        }

        $charge = $charges->first();
        // AnnulSaleCommand has no separate instant; keep the reversal in the sale's commercial day.
        $occurredAt = CarbonImmutable::instance($sale->completada_en)->setTimezone('UTC');
        $attributes = [
            $foreignKey => $foreignId,
            'metodo_pago_id' => (int) $charge->metodo_pago_id,
            'sesion_caja_id' => (int) $charge->sesion_caja_id,
            'usuario_id' => (int) $actor->getKey(),
            'clave_idempotencia' => $command->commandUuid,
            'tipo' => PaymentType::Refund->value,
            'monto' => Decimal::money((string) $charge->monto),
            'monto_recibido' => null,
            'vuelto' => null,
            'ocurrido_en' => $occurredAt,
            'observacion' => $command->reason,
        ];

        $paymentId = (int) DB::table($table)->insertGetId($attributes);

        return [
            'payment_id' => $paymentId,
            'payment_table' => $table,
            'occurred_at' => $occurredAt,
            'amount' => Decimal::money((string) $charge->monto),
        ];
    }

    private function rebuildDailyStock(
        array $articleIds,
        string $fromCommercialDate,
    ): void {
        if (! $this->hasUnanchoredSaleOpening($articleIds, $fromCommercialDate)) {
            $this->dailyStock->rebuild($articleIds, $fromCommercialDate);
            return;
        }

        $connection = DB::connection();
        $dailyTable = 'stock_diario';
        $ledgerTable = 'movimientos_inventario';
        $today = CommercialDate::today();
        $calculatedAt = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
        $existing = $connection->table($dailyTable)
            ->whereIn('articulo_id', $articleIds)
            ->where('fecha_comercial', '>=', $fromCommercialDate)
            ->orderBy('articulo_id')
            ->orderBy('fecha_comercial')
            ->lockForUpdate()
            ->get(['articulo_id', 'fecha_comercial'])
            ->groupBy('articulo_id');

        foreach ($articleIds as $articleId) {
            $movements = $connection->table($ledgerTable)
                ->where('articulo_id', $articleId)
                ->orderBy('ocurrido_en')
                ->orderBy('id')
                ->get(['id', 'cantidad_delta', 'stock_anterior', 'stock_posterior', 'ocurrido_en']);
            $opening = null;
            $days = [];
            $lastLedgerDate = '';

            foreach ($movements as $movement) {
                $date = CommercialDate::fromInstant(CarbonImmutable::parse($movement->ocurrido_en, 'UTC'));
                $lastLedgerDate = max($lastLedgerDate, $date);

                if ($date < $fromCommercialDate) {
                    $opening = Decimal::quantity((string) $movement->stock_posterior);

                    continue;
                }

                if ($opening === null) {
                    $opening = Decimal::quantity((string) $movement->stock_anterior);
                }

                $days[$date] ??= ['entries' => '0.000', 'outputs' => '0.000', 'watermark' => null];
                $delta = Decimal::quantity((string) $movement->cantidad_delta);

                if (Decimal::sign($delta) > 0) {
                    $days[$date]['entries'] = Decimal::add($days[$date]['entries'], $delta, 3);
                } else {
                    $days[$date]['outputs'] = Decimal::add(
                        $days[$date]['outputs'],
                        Decimal::subtract('0.000', $delta, 3),
                        3,
                    );
                }

                $days[$date]['watermark'] = (int) $movement->id;
            }

            if ($opening === null) {
                continue;
            }

            $projection = $existing->get($articleId);
            $lastProjectionDate = $projection?->max('fecha_comercial') ?? '';
            $horizon = max($today, $lastProjectionDate, $lastLedgerDate);
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $fromCommercialDate, 'UTC');
            $rows = [];

            while (($date = $day->format('Y-m-d')) <= $horizon) {
                $entries = Decimal::quantity($days[$date]['entries'] ?? '0.000');
                $outputs = Decimal::quantity($days[$date]['outputs'] ?? '0.000');
                $closing = Decimal::quantity(Decimal::subtract(
                    Decimal::add($opening, $entries, 3),
                    $outputs,
                    3,
                ));

                if (Decimal::sign($opening) < 0 || Decimal::sign($closing) < 0) {
                    throw new InventoryDomainException('El ledger deriva un stock diario negativo.');
                }

                $watermark = $days[$date]['watermark'] ?? null;
                $rows[] = [
                    'articulo_id' => $articleId,
                    'fecha_comercial' => $date,
                    'stock_apertura' => $opening,
                    'total_entradas' => $entries,
                    'total_salidas' => $outputs,
                    'stock_cierre' => $closing,
                    'calculado_en' => $calculatedAt,
                    'ultimo_movimiento_id' => $watermark,
                    'created_at' => $calculatedAt,
                    'updated_at' => $calculatedAt,
                ];
                $opening = $closing;
                $day = $day->addDay();
            }

            foreach (array_chunk($rows, 500) as $batch) {
                $connection->table($dailyTable)->upsert(
                    $batch,
                    ['articulo_id', 'fecha_comercial'],
                    [
                        'stock_apertura',
                        'total_entradas',
                        'total_salidas',
                        'stock_cierre',
                        'calculado_en',
                        'ultimo_movimiento_id',
                        'updated_at',
                    ],
                );
            }
        }
    }

    private function hasUnanchoredSaleOpening(array $articleIds, string $fromCommercialDate): bool
    {
        foreach ($articleIds as $articleId) {
            $first = DB::table('movimientos_inventario')
                ->where('articulo_id', $articleId)
                ->orderBy('ocurrido_en')
                ->orderBy('id')
                ->first(['tipo_movimiento', 'stock_anterior', 'ocurrido_en']);

            if ($first === null
                || (string) $first->tipo_movimiento !== InventoryMovementType::Sale->value
                || CommercialDate::fromInstant(CarbonImmutable::parse($first->ocurrido_en, 'UTC')) < $fromCommercialDate
                || Decimal::sign(Decimal::quantity((string) $first->stock_anterior)) <= 0) {
                return false;
            }
        }

        return true;
    }

    private function nullableDecimal(mixed $value, int $scale): ?string
    {
        return $value === null ? null : ($scale === 2 ? Decimal::money((string) $value) : (string) $value);
    }
}
