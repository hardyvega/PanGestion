<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Data\Orders\CancelOrderCommand;
use App\Data\Orders\CreateOrderCommand;
use App\Data\Orders\DeliverOrderCommand;
use App\Data\Orders\OrderPaymentData;
use App\Data\Orders\TransitionOrderCommand;
use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Cash\CashPostingService;
use App\Services\Cash\CashSessionGuard;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\LockedArticleSet;
use App\Services\Inventory\StockDailyRebuilder;
use App\Services\Sales\SaleCorrelativeAllocator;
use App\Support\CommercialDate;
use App\Support\Decimal;
use App\Support\PostgresUniqueViolation;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly CashPostingService $cashPosting,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stock,
        private readonly StockDailyRebuilder $dailyStock,
        private readonly SaleCorrelativeAllocator $correlatives,
    ) {
    }

    public function create(User $actor, CreateOrderCommand $command): Pedido
    {
        $requiredPermissions = $command->payments === []
            ? [PermissionCode::OrdersCreate]
            : [PermissionCode::OrdersCreate, PermissionCode::OrdersCollect];
        $this->permissions->authorizeAll($actor, $requiredPermissions);

        try {
            return DB::transaction(function () use ($actor, $command): Pedido {
            $existing = Pedido::query()
                ->where('clave_idempotencia', $command->commandUuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertCompatibleCreate($existing, $actor, $command);

                return $existing;
            }

            $this->assertPaymentUuidAvailability($command->payments);
            if ($command->clientId !== null && ! DB::table('clientes')->where('id', $command->clientId)->exists()) {
                throw (new ModelNotFoundException())->setModel('clientes', [$command->clientId]);
            }

            $articles = $command->freeOrder === null ? $this->loadArticlesForLines($command) : collect();
            $total = $command->freeOrder?->total ?? $this->orderTotal($command);
            $this->lockPrincipalPaymentSessions($command->payments);
            $this->validatePaymentMethods($command->payments);
            $this->assertNetPaymentWithinTotal($command->payments, $total);

            $now = CarbonImmutable::now('UTC');
            $orderId = (int) DB::table('pedidos')->insertGetId([
                'cliente_id' => $command->clientId,
                'cliente_nombre' => $command->contact?->name,
                'cliente_telefono_cifrado' => $command->contact === null ? null : Crypt::encryptString($command->contact->phone),
                'modalidad' => $command->freeOrder === null ? Pedido::MODE_CATALOG : Pedido::MODE_FREE,
                'detalle_texto' => $command->freeOrder?->description,
                'especificaciones_texto' => $command->freeOrder?->specifications,
                'precio_total_acordado' => $command->freeOrder?->total,
                'usuario_creador_id' => (int) $actor->getKey(),
                'estado' => OrderStatus::Pending->value,
                'entrega_programada_en' => $this->commandDate($command->scheduledDeliveryAt),
                'observacion' => $command->observation,
                'clave_idempotencia' => $command->commandUuid,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($command->lines as $line) {
                $article = $articles->get($line->articleId);

                DB::table('detalle_pedidos')->insert([
                    'pedido_id' => $orderId,
                    'articulo_id' => $line->articleId,
                    'cantidad' => $line->quantity,
                    'precio_unitario' => $line->unitPrice,
                    'observacion' => $line->observation,
                    'articulo_sku_snapshot' => $article->sku,
                    'articulo_nombre_snapshot' => $article->nombre,
                    'unidad_codigo_snapshot' => $article->unidad_codigo,
                    'unidad_simbolo_snapshot' => $article->unidad_simbolo,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($command->payments as $payment) {
                $paymentId = (int) DB::table('pagos_pedido')->insertGetId([
                    'pedido_id' => $orderId,
                    'metodo_pago_id' => $payment->paymentMethodId,
                    'sesion_caja_id' => $payment->cashSessionId,
                    'usuario_id' => (int) $actor->getKey(),
                    'clave_idempotencia' => $payment->commandUuid,
                    'tipo' => $payment->type->value,
                    'monto' => $payment->amount,
                    'monto_recibido' => $payment->receivedAmount,
                    'vuelto' => $payment->change,
                    'ocurrido_en' => $now,
                    'observacion' => $payment->observation,
                    'created_at' => $now,
                ]);
                $this->cashPosting->postOrderPayment($paymentId);
            }

            DB::table('transiciones_pedido')->insert([
                'pedido_id' => $orderId,
                'estado_anterior' => null,
                'estado_nuevo' => OrderStatus::Pending->value,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $command->commandUuid,
                'motivo' => null,
                'ocurrida_en' => $now,
                'created_at' => $now,
            ]);

            return Pedido::query()->findOrFail($orderId);
            });
        } catch (QueryException $exception) {
            if (! PostgresUniqueViolation::for($exception, 'pedidos_clave_idempotencia_unique')) {
                throw $exception;
            }

            return DB::transaction(function () use ($actor, $command, $exception): Pedido {
                $existing = Pedido::query()
                    ->where('clave_idempotencia', $command->commandUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    throw $exception;
                }

                $this->assertCompatibleCreate($existing, $actor, $command);

                return $existing;
            });
        }
    }

    public function transition(User $actor, TransitionOrderCommand $command): Pedido
    {
        $this->permissions->authorize($actor, PermissionCode::OrdersManage);

        try {
            return DB::transaction(function () use ($actor, $command): Pedido {
            $order = Pedido::query()
                ->whereKey($command->orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw (new ModelNotFoundException())->setModel(Pedido::class, [$command->orderId]);
            }

            $existing = DB::table('transiciones_pedido')
                ->where('clave_idempotencia', $command->commandUuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((int) $existing->pedido_id !== (int) $order->getKey()
                    || (int) $existing->usuario_id !== (int) $actor->getKey()
                    || $existing->estado_nuevo !== $command->target->value
                    || $existing->motivo !== $command->reason) {
                    throw new IdempotencyConflictException('El UUID de transicion ya fue usado con otra intencion.');
                }

                return $order;
            }

            $allowedTarget = match ($order->estado) {
                OrderStatus::Pending => OrderStatus::Confirmed,
                OrderStatus::Confirmed => OrderStatus::InPreparation,
                OrderStatus::InPreparation => OrderStatus::Ready,
                default => null,
            };

            if ($allowedTarget !== $command->target) {
                throw new InventoryDomainException('La transicion de estado no esta permitida.');
            }

            $now = $this->commandInstant($command->occurredAt);
            DB::table('transiciones_pedido')->insert([
                'pedido_id' => (int) $order->getKey(),
                'estado_anterior' => $order->estado->value,
                'estado_nuevo' => $command->target->value,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $command->commandUuid,
                'motivo' => $command->reason,
                'ocurrida_en' => $now,
                'created_at' => $now,
            ]);

            DB::table('pedidos')
                ->where('id', $order->getKey())
                ->update([
                    'estado' => $command->target->value,
                    'updated_at' => $now,
                ]);

            return Pedido::query()->findOrFail($order->getKey());
            });
        } catch (QueryException $exception) {
            if (! PostgresUniqueViolation::for($exception, 'transiciones_pedido_clave_idempotencia_unique')) {
                throw $exception;
            }

            // The unique winner is resolved only after PostgreSQL has rolled
            // back the losing transaction.
            return $this->deliver($actor, $command);
        }
    }

    public function cancel(User $actor, CancelOrderCommand $command): Pedido
    {
        $this->permissions->authorize($actor, PermissionCode::OrdersCancel);

        return DB::transaction(function () use ($actor, $command): Pedido {
            $order = Pedido::query()
                ->whereKey($command->orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw (new ModelNotFoundException())->setModel(Pedido::class, [$command->orderId]);
            }

            $existingTransition = DB::table('transiciones_pedido')
                ->where('clave_idempotencia', $command->commandUuid)
                ->lockForUpdate()
                ->first();

            if ($existingTransition !== null) {
                if ((int) $existingTransition->pedido_id !== (int) $order->getKey()
                    || $existingTransition->estado_nuevo !== OrderStatus::Cancelled->value
                    || (int) $existingTransition->usuario_id !== (int) $actor->getKey()
                    || $existingTransition->motivo !== $command->reason) {
                    throw new IdempotencyConflictException('El UUID de cancelacion ya fue usado con otra intencion.');
                }

                $existingPayment = DB::table('pagos_pedido')
                    ->where('clave_idempotencia', $command->commandUuid)
                    ->lockForUpdate()
                    ->first();

                if ($existingPayment !== null && $existingPayment->tipo !== PaymentType::Refund->value) {
                    throw new IdempotencyConflictException('El UUID de devolucion colisiona con otro pago.');
                }

                return $order;
            }

            if ($order->estado === OrderStatus::Delivered) {
                throw new InventoryDomainException('Un pedido entregado no se puede cancelar.');
            }
            if ($order->estado === OrderStatus::Cancelled) {
                throw new InventoryDomainException('El pedido ya esta cancelado.');
            }
            if (! in_array($order->estado, [
                OrderStatus::Pending,
                OrderStatus::Confirmed,
                OrderStatus::InPreparation,
                OrderStatus::Ready,
            ], true)) {
                throw new InventoryDomainException('El estado actual no admite cancelacion.');
            }

            $paymentRows = $this->lockCancellationPayments((int) $order->getKey());
            $refund = $this->resolveRefund($paymentRows);
            $collision = DB::table('pagos_pedido')
                ->where('clave_idempotencia', $command->commandUuid)
                ->lockForUpdate()
                ->first();

            if ($collision !== null) {
                throw new IdempotencyConflictException('El UUID de cancelacion ya fue usado por otro pago.');
            }

            $now = $this->commandInstant(null);

            if ($refund !== null) {
                $paymentId = (int) DB::table('pagos_pedido')->insertGetId([
                    'pedido_id' => (int) $order->getKey(),
                    'metodo_pago_id' => $refund['payment_method_id'],
                    'sesion_caja_id' => $refund['cash_session_id'],
                    'usuario_id' => (int) $actor->getKey(),
                    'clave_idempotencia' => $command->commandUuid,
                    'tipo' => PaymentType::Refund->value,
                    'monto' => $refund['amount'],
                    'monto_recibido' => null,
                    'vuelto' => null,
                    'ocurrido_en' => $now,
                    'observacion' => null,
                    'created_at' => $now,
                ]);
                $this->cashPosting->postOrderPayment($paymentId);
            }

            DB::table('transiciones_pedido')->insert([
                'pedido_id' => (int) $order->getKey(),
                'estado_anterior' => $order->estado->value,
                'estado_nuevo' => OrderStatus::Cancelled->value,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $command->commandUuid,
                'motivo' => $command->reason,
                'ocurrida_en' => $now,
                'created_at' => $now,
            ]);

            DB::table('pedidos')
                ->where('id', $order->getKey())
                ->update([
                    'estado' => OrderStatus::Cancelled->value,
                    'cancelado_en' => $now,
                    'updated_at' => $now,
                ]);

            return Pedido::query()->findOrFail($order->getKey());
        });
    }

    public function deliver(User $actor, DeliverOrderCommand $command): Pedido
    {
        $required = [PermissionCode::OrdersDeliver];
        if ($command->finalPayment !== null) {
            $required[] = PermissionCode::OrdersCollect;
        }
        $this->permissions->authorizeAll($actor, $required);

        return DB::transaction(function () use ($actor, $command): Pedido {
            $order = Pedido::query()
                ->whereKey($command->orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                throw (new ModelNotFoundException())->setModel(Pedido::class, [$command->orderId]);
            }

            $existingTransition = DB::table('transiciones_pedido')
                ->where('clave_idempotencia', $command->commandUuid)
                ->lockForUpdate()
                ->first();

            if ($existingTransition !== null) {
                $this->assertCompatibleDelivery($order, $actor, $command, $existingTransition);

                return $order;
            }

            if ($order->estado !== OrderStatus::Ready) {
                throw new InventoryDomainException('Solo se pueden entregar pedidos listos.');
            }

            $details = DB::table('detalle_pedidos')
                ->where('pedido_id', $order->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($order->isFree() && $details->isNotEmpty()) {
                throw new InventoryDomainException('El pedido tiene datos incompatibles. Solicita ayuda para revisarlo antes de entregar.');
            }
            if (! $order->isFree() && $details->isEmpty()) {
                throw new InventoryDomainException('El pedido no tiene detalles para entregar.');
            }

            foreach ($details as $detail) {
                foreach ([
                    'articulo_sku_snapshot',
                    'articulo_nombre_snapshot',
                    'unidad_codigo_snapshot',
                    'unidad_simbolo_snapshot',
                ] as $snapshot) {
                    if ($detail->{$snapshot} === null || trim((string) $detail->{$snapshot}) === '') {
                        throw new InventoryDomainException('El pedido contiene detalles sin snapshots completos.');
                    }
                }
            }

            $payments = DB::table('pagos_pedido')
                ->where('pedido_id', $order->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $advance = $this->netPayment($payments);
            $total = Decimal::money($order->totalFrom($details));

            if (Decimal::compare($advance, '0.00', 2) < 0
                || Decimal::compare($advance, $total, 2) > 0) {
                throw new InventoryDomainException('El pago neto previo no puede quedar fuera del total del pedido.');
            }

            $finalPayment = $command->finalPayment;
            if (Decimal::compare($advance, $total, 2) === 0) {
                if ($finalPayment !== null) {
                    throw new InventoryDomainException('El pedido ya esta completamente pagado.');
                }
            } else {
                if ($finalPayment === null || $finalPayment->type !== PaymentType::Charge) {
                    throw new InventoryDomainException('La entrega requiere un pago final exacto.');
                }
                if ($finalPayment->commandUuid === $command->commandUuid) {
                    throw new IdempotencyConflictException('El UUID del pago final debe ser distinto al UUID de entrega.');
                }
                $expected = Decimal::subtract($total, $advance, 2);
                if (Decimal::compare($finalPayment->amount, $expected, 2) !== 0) {
                    throw new InventoryDomainException('El pago final debe cubrir exactamente el saldo del pedido.');
                }
                $this->assertPaymentUuidAvailability([$finalPayment]);
                $this->validatePaymentMethods([$finalPayment]);
            }

            $deliveryQuery = CashSessionGuard::operationalPrincipalQuery(CommercialDate::today());
            if ($command->expectedCashSessionId !== null) {
                $deliveryQuery->where('sesiones_caja.id', $command->expectedCashSessionId);
            }
            if ($finalPayment !== null) {
                $deliveryQuery->where('sesiones_caja.id', $finalPayment->cashSessionId);
            }
            $deliverySession = $deliveryQuery->lock('for update of sesiones_caja')->first(['sesiones_caja.*']);
            if ($deliverySession === null) {
                throw new InventoryDomainException('La entrega requiere una sesión vigente de Caja principal.');
            }
            $deliveryAt = CarbonImmutable::now('UTC');
            CashSessionGuard::assertOpen($deliverySession, $deliveryAt);
            $cashSessionId = (int) $deliverySession->id;
            $commercialDate = CommercialDate::fromInstant($deliveryAt);
            $number = $this->correlatives->next($commercialDate);
            $articles = $order->isFree() ? null : $this->lockCatalogArticles($details);

            $saleId = (int) DB::table('ventas')->insertGetId([
                'fecha_comercial' => $commercialDate,
                'numero_diario' => $number,
                'clave_idempotencia' => $command->commandUuid,
                'usuario_id' => (int) $actor->getKey(),
                'sesion_caja_id' => $cashSessionId,
                'cliente_id' => $order->cliente_id,
                'pedido_id' => (int) $order->getKey(),
                'estado' => 'completada',
                'total' => $total,
                'monto_anticipo_aplicado' => $advance,
                'completada_en' => $deliveryAt,
                'observacion' => null,
            ]);

            if ($articles !== null) {
                $this->recordCatalogSale($saleId, $details, $articles, (int) $actor->getKey(), $command->commandUuid, $deliveryAt);
            }

            if ($finalPayment !== null) {
                $paymentId = (int) DB::table('pagos_pedido')->insertGetId([
                    'pedido_id' => (int) $order->getKey(),
                    'metodo_pago_id' => $finalPayment->paymentMethodId,
                    'sesion_caja_id' => $finalPayment->cashSessionId,
                    'usuario_id' => (int) $actor->getKey(),
                    'clave_idempotencia' => $finalPayment->commandUuid,
                    'tipo' => PaymentType::Charge->value,
                    'monto' => $finalPayment->amount,
                    'monto_recibido' => $finalPayment->receivedAmount,
                    'vuelto' => $finalPayment->change,
                    'ocurrido_en' => $deliveryAt,
                    'observacion' => $finalPayment->observation,
                    'created_at' => $deliveryAt,
                ]);
                $this->cashPosting->postOrderPayment($paymentId);
            }

            DB::table('pedidos')->where('id', $order->getKey())->update([
                'estado' => OrderStatus::Delivered->value,
                'entregado_en' => $deliveryAt,
                'updated_at' => $deliveryAt,
            ]);
            DB::table('transiciones_pedido')->insert([
                'pedido_id' => (int) $order->getKey(),
                'estado_anterior' => OrderStatus::Ready->value,
                'estado_nuevo' => OrderStatus::Delivered->value,
                'usuario_id' => (int) $actor->getKey(),
                'clave_idempotencia' => $command->commandUuid,
                'motivo' => null,
                'ocurrida_en' => $deliveryAt,
                'created_at' => $deliveryAt,
            ]);

            $deliveredOrder = Pedido::query()->findOrFail($order->getKey());
            CashSessionGuard::assertOpen($deliverySession);

            return $deliveredOrder;
        });
    }

    private function assertCompatibleDelivery(
        Pedido $order,
        User $actor,
        DeliverOrderCommand $command,
        object $transition,
    ): void {
        if ((int) $transition->pedido_id !== (int) $order->getKey()
            || $transition->estado_nuevo !== OrderStatus::Delivered->value
            || (int) $transition->usuario_id !== (int) $actor->getKey()) {
            throw new IdempotencyConflictException('El UUID de entrega ya fue usado con otra intencion.');
        }

        $sale = DB::table('ventas')->where('clave_idempotencia', $command->commandUuid)->first();
        if ($sale === null || (int) $sale->pedido_id !== (int) $order->getKey()) {
            throw new InventoryDomainException('La entrega idempotente no tiene una venta derivada compatible.');
        }
        if ($command->expectedCashSessionId !== null && (int) $sale->sesion_caja_id !== $command->expectedCashSessionId) {
            throw new IdempotencyConflictException('El UUID de entrega ya fue usado con otra sesión de caja.');
        }

        $hasFinalPayment = Decimal::compare((string) $sale->monto_anticipo_aplicado, (string) $sale->total, 2) < 0;
        if ($hasFinalPayment !== ($command->finalPayment !== null)) {
            throw new IdempotencyConflictException('El UUID de entrega ya fue usado con otra intencion de pago.');
        }

        if ($command->finalPayment !== null) {
            $payment = DB::table('pagos_pedido')
                ->where('pedido_id', $order->getKey())
                ->where('clave_idempotencia', $command->finalPayment->commandUuid)
                ->first();
            if ($payment === null
                || (int) $payment->metodo_pago_id !== $command->finalPayment->paymentMethodId
                || (int) $payment->sesion_caja_id !== $command->finalPayment->cashSessionId
                || $payment->tipo !== $command->finalPayment->type->value
                || (string) $payment->monto !== $command->finalPayment->amount
                || $payment->monto_recibido !== $command->finalPayment->receivedAmount
                || $payment->vuelto !== $command->finalPayment->change) {
                throw new IdempotencyConflictException('El UUID de entrega ya fue usado con otro pago final.');
            }
        }
    }

    private function netPayment(Collection $payments): string
    {
        $net = '0.00';
        foreach ($payments as $payment) {
            $net = $payment->tipo === PaymentType::Charge->value
                ? Decimal::add($net, (string) $payment->monto, 2)
                : Decimal::subtract($net, (string) $payment->monto, 2);
        }

        return Decimal::money($net);
    }

    private function lockCatalogArticles(Collection $details): LockedArticleSet
    {
        $articleIds = $details->pluck('articulo_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $articles = $this->lockManager->lockArticles($articleIds);

        foreach ($articles as $article) {
            if (! (bool) $article->activo
                || ! in_array($article->tipo_articulo, ['elaborado', 'reventa'], true)) {
                throw new InventoryDomainException('El articulo del pedido no esta habilitado para la venta.');
            }
        }

        return $articles;
    }

    private function recordCatalogSale(
        int $saleId,
        Collection $details,
        LockedArticleSet $articles,
        int $actorId,
        string $commandUuid,
        CarbonImmutable $deliveryAt,
    ): void {
        $detailIds = [];
        foreach ($details as $detail) {
            $detailIds[(int) $detail->id] = (int) DB::table('detalle_ventas')->insertGetId([
                'venta_id' => $saleId,
                'articulo_id' => (int) $detail->articulo_id,
                'articulo_sku_snapshot' => $detail->articulo_sku_snapshot,
                'articulo_nombre_snapshot' => $detail->articulo_nombre_snapshot,
                'unidad_codigo_snapshot' => $detail->unidad_codigo_snapshot,
                'unidad_simbolo_snapshot' => $detail->unidad_simbolo_snapshot,
                'cantidad' => $detail->cantidad,
                'precio_unitario' => $detail->precio_unitario,
                'subtotal' => Decimal::multiply((string) $detail->cantidad, (string) $detail->precio_unitario, 2),
            ]);
        }

        $positions = [];
        $mutations = [];
        foreach ($details as $detail) {
            $articleId = (int) $detail->articulo_id;
            $position = ($positions[$articleId] ?? 0) + 1;
            $positions[$articleId] = $position;
            $mutations[] = new InventoryStockMutation(
                articleId: $articleId,
                type: InventoryMovementType::Sale,
                delta: '-'.Decimal::quantity((string) $detail->cantidad),
                position: $position,
                occurredAt: $deliveryAt,
                actorId: $actorId,
                authorizerId: null,
                reason: null,
                unitCode: (string) $detail->unidad_codigo_snapshot,
                unitSymbol: (string) $detail->unidad_simbolo_snapshot,
                saleDetailId: $detailIds[(int) $detail->id],
                receiptDetailId: null,
                productionDetailId: null,
                productionConsumptionId: null,
                wasteId: null,
                inventoryCountDetailId: null,
                reversedMovementId: null,
            );
        }

        $simulation = $this->stock->simulatePlan($articles, $commandUuid, $mutations);
        $this->stock->applyPlan($simulation);
        $this->rebuildDailyStock($articles->ids(), CommercialDate::fromInstant($deliveryAt));
    }

    private function rebuildDailyStock(array $articleIds, string $fromCommercialDate): void
    {
        if (! $this->hasUnanchoredSaleOpening($articleIds, $fromCommercialDate)) {
            $this->dailyStock->rebuild($articleIds, $fromCommercialDate);
            return;
        }

        $connection = DB::connection();
        $existing = $connection->table('stock_diario')
            ->whereIn('articulo_id', $articleIds)
            ->where('fecha_comercial', '>=', $fromCommercialDate)
            ->orderBy('articulo_id')
            ->orderBy('fecha_comercial')
            ->lockForUpdate()
            ->get(['articulo_id', 'fecha_comercial'])
            ->groupBy('articulo_id');
        $today = CommercialDate::today();
        $calculatedAt = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

        foreach ($articleIds as $articleId) {
            $movements = $connection->table('movimientos_inventario')
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

                $rows[] = [
                    'articulo_id' => $articleId,
                    'fecha_comercial' => $date,
                    'stock_apertura' => $opening,
                    'total_entradas' => $entries,
                    'total_salidas' => $outputs,
                    'stock_cierre' => $closing,
                    'calculado_en' => $calculatedAt,
                    'ultimo_movimiento_id' => $days[$date]['watermark'] ?? null,
                    'created_at' => $calculatedAt,
                    'updated_at' => $calculatedAt,
                ];
                $opening = $closing;
                $day = $day->addDay();
            }

            foreach (array_chunk($rows, 500) as $batch) {
                $connection->table('stock_diario')->upsert(
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

    private function assertCompatibleCreate(Pedido $existing, User $actor, CreateOrderCommand $command): void
    {
        if ((int) $existing->usuario_creador_id !== (int) $actor->getKey()
            || $existing->cliente_id !== $command->clientId
            || $existing->cliente_nombre !== $command->contact?->name
            || $existing->cliente_telefono_cifrado !== $command->contact?->phone
            || $existing->isFree() !== ($command->freeOrder !== null)
            || $existing->detalle_texto !== $command->freeOrder?->description
            || $existing->especificaciones_texto !== $command->freeOrder?->specifications
            || $existing->precio_total_acordado !== $command->freeOrder?->total
            || $existing->observacion !== $command->observation
            || ! $this->sameDate($existing->entrega_programada_en, $command->scheduledDeliveryAt)) {
            throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otra intencion.');
        }

        $details = DB::table('detalle_pedidos')
            ->where('pedido_id', $existing->getKey())
            ->orderBy('id')
            ->get();

        if ($details->count() !== count($command->lines)) {
            throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otras lineas.');
        }

        foreach ($command->lines as $index => $line) {
            $detail = $details->get($index);
            if ((int) $detail->articulo_id !== $line->articleId
                || (string) $detail->cantidad !== $line->quantity
                || (string) $detail->precio_unitario !== $line->unitPrice
                || $detail->observacion !== $line->observation) {
                throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otras lineas.');
            }
        }

        $payments = DB::table('pagos_pedido')
            ->where('pedido_id', $existing->getKey())
            ->orderBy('id')
            ->get();

        if ($payments->count() !== count($command->payments)) {
            throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otros pagos.');
        }

        foreach ($command->payments as $index => $payment) {
            $stored = $payments->get($index);
            if ((int) $stored->metodo_pago_id !== $payment->paymentMethodId
                || (int) $stored->sesion_caja_id !== $payment->cashSessionId
                || $stored->usuario_id !== (int) $actor->getKey()
                || $stored->clave_idempotencia !== $payment->commandUuid
                || $stored->tipo !== $payment->type->value
                || (string) $stored->monto !== $payment->amount
                || $stored->monto_recibido !== $payment->receivedAmount
                || $stored->vuelto !== $payment->change
                || $stored->observacion !== $payment->observation) {
                throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otros pagos.');
            }
        }

        $transition = DB::table('transiciones_pedido')
            ->where('pedido_id', $existing->getKey())
            ->orderBy('id')
            ->first();

        if ($transition === null
            || $transition->estado_anterior !== null
            || $transition->estado_nuevo !== OrderStatus::Pending->value
            || (int) $transition->usuario_id !== (int) $actor->getKey()
            || $transition->clave_idempotencia !== $command->commandUuid
            || $transition->motivo !== null) {
            throw new IdempotencyConflictException('El UUID de creacion ya fue usado con otro historial.');
        }
    }

    private function assertPaymentUuidAvailability(array $payments): void
    {
        $uuids = [];
        foreach ($payments as $payment) {
            if (isset($uuids[$payment->commandUuid])) {
                throw new IdempotencyConflictException('El UUID de pago se repite en el comando.');
            }
            $uuids[$payment->commandUuid] = true;
        }

        if ($uuids !== [] && DB::table('pagos_pedido')->whereIn('clave_idempotencia', array_keys($uuids))->exists()) {
            throw new IdempotencyConflictException('El UUID de pago ya fue usado.');
        }
    }

    private function loadArticlesForLines(CreateOrderCommand $command): Collection
    {
        $ids = array_values(array_unique(array_map(
            static fn ($line): int => $line->articleId,
            $command->lines,
        )));
        $articles = DB::table('articulos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->whereIn('articulos.id', $ids)
            ->get([
                'articulos.id',
                'articulos.sku',
                'articulos.nombre',
                'articulos.unidad_id',
                'unidades_medida.codigo as unidad_codigo',
                'unidades_medida.simbolo as unidad_simbolo',
            ])
            ->keyBy('id');

        foreach ($ids as $articleId) {
            if (! $articles->has($articleId)) {
                throw (new ModelNotFoundException())->setModel(Articulo::class, [$articleId]);
            }
        }

        return $articles;
    }

    private function orderTotal(CreateOrderCommand $command): string
    {
        $total = '0.00';
        foreach ($command->lines as $line) {
            $total = Decimal::add(
                $total,
                Decimal::multiply($line->quantity, $line->unitPrice, 2),
                2,
            );
        }

        return $total;
    }

    private function validatePaymentMethods(array $payments): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (OrderPaymentData $payment): int => $payment->paymentMethodId,
            $payments,
        )));
        if ($ids === []) {
            return;
        }

        $methods = DB::table('metodos_pago')->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $method = $methods->get($id);
            if ($method === null) {
                throw (new ModelNotFoundException())->setModel('metodos_pago', [$id]);
            }
            if (! (bool) $method->activo) {
                throw new InventoryDomainException('El metodo de pago debe estar activo.');
            }
        }
    }

    private function lockPrincipalPaymentSessions(array $payments): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (OrderPaymentData $payment): int => $payment->cashSessionId,
            $payments,
        )));
        sort($ids);

        foreach ($ids as $id) {
            $session = CashSessionGuard::operationalPrincipalQuery(CommercialDate::today())
                ->where('sesiones_caja.id', $id)
                ->lock('for update of sesiones_caja')
                ->first(['sesiones_caja.*']);
            if ($session === null) {
                throw new InventoryDomainException('Para registrar un abono, Caja principal debe estar abierta en la jornada actual.');
            }
            CashSessionGuard::assertOpen($session);
        }
    }

    private function assertNetPaymentWithinTotal(array $payments, string $total): void
    {
        $net = '0.00';
        foreach ($payments as $payment) {
            $net = $payment->type === PaymentType::Charge
                ? Decimal::add($net, $payment->amount, 2)
                : Decimal::subtract($net, $payment->amount, 2);
        }

        if (Decimal::compare($net, '0.00', 2) < 0 || Decimal::compare($net, $total, 2) > 0) {
            throw new InventoryDomainException('El pago neto debe estar entre cero y el total del pedido.');
        }
    }

    private function lockCancellationPayments(int $orderId): Collection
    {
        $sessionIds = DB::table('pagos_pedido')
            ->where('pedido_id', $orderId)
            ->distinct()
            ->pluck('sesion_caja_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        foreach ($sessionIds as $sessionId) {
            $session = DB::table('sesiones_caja')->where('id', $sessionId)->lockForUpdate()->first();
            if ($session !== null) {
                CashSessionGuard::assertOpen($session);
            }
        }

        return DB::table('pagos_pedido')
            ->where('pedido_id', $orderId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function resolveRefund(Collection $payments): ?array
    {
        $balances = [];

        foreach ($payments as $payment) {
            if ($payment->tipo === PaymentType::Charge->value) {
                $balances[] = [
                    'payment' => $payment,
                    'balance' => (string) $payment->monto,
                ];
                continue;
            }

            $positive = array_keys(array_filter(
                $balances,
                static fn (array $balance): bool => Decimal::compare($balance['balance'], '0.00', 2) > 0,
            ));
            $exact = array_values(array_filter(
                $positive,
                static fn (int $index): bool => Decimal::compare(
                    $balances[$index]['balance'],
                    (string) $payment->monto,
                    2,
                ) === 0,
            ));

            if (count($exact) === 1) {
                $balances[$exact[0]]['balance'] = '0.00';
                continue;
            }

            if (count($positive) !== 1) {
                throw new InventoryDomainException('La devolucion no tiene un saldo reembolsable inequívoco.');
            }

            $index = $positive[0];
            if (Decimal::compare((string) $payment->monto, $balances[$index]['balance'], 2) > 0) {
                throw new InventoryDomainException('Las devoluciones superan los cobros del pedido.');
            }
            $balances[$index]['balance'] = Decimal::subtract(
                $balances[$index]['balance'],
                (string) $payment->monto,
                2,
            );
        }

        $positive = array_values(array_filter(
            $balances,
            static fn (array $balance): bool => Decimal::compare($balance['balance'], '0.00', 2) > 0,
        ));

        if ($positive === []) {
            return null;
        }
        if (count($positive) > 1) {
            throw new InventoryDomainException('Existen multiples saldos reembolsables positivos.');
        }

        $payment = $positive[0]['payment'];

        return [
            'amount' => $positive[0]['balance'],
            'payment_method_id' => (int) $payment->metodo_pago_id,
            'cash_session_id' => (int) $payment->sesion_caja_id,
        ];
    }

    private function commandDate(?DateTimeInterface $value): ?CarbonImmutable
    {
        return $value === null ? null : $this->commandInstant($value);
    }

    private function commandInstant(?DateTimeInterface $value): CarbonImmutable
    {
        return $value === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::instance($value)->setTimezone('UTC');
    }

    private function sameDate(?DateTimeInterface $stored, ?DateTimeInterface $command): bool
    {
        if ($stored === null || $command === null) {
            return $stored === null && $command === null;
        }

        return CarbonImmutable::instance($stored)
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s.uP')
            === $this->commandInstant($command)->format('Y-m-d H:i:s.uP');
    }
}
