<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Enums\ReceiptOriginType;
use App\Enums\ReceiptStatus;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\DetalleRecepcionMercaderia;
use App\Models\MovimientoInventario;
use App\Models\RecepcionMercaderia;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReceiptService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stockService,
        private readonly StockDailyRebuilder $rebuilder,
    ) {
    }

    public function createDraft(
        User $actor,
        string $commandUuid,
        ReceiptOriginType $origin,
        ?int $supplierId = null,
        ?int $contributorId = null,
        ?string $originDetail = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $occurrenceReason = null,
        ?string $observation = null,
    ): RecepcionMercaderia {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsCreate);

        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('El comando debe tener un UUID valido.');
        }

        $commandUuid = strtolower($commandUuid);

        return DB::transaction(function () use (
            $actor, $commandUuid, $origin, $supplierId, $contributorId, $originDetail,
            $occurredAt, $occurrenceReason, $observation,
        ): RecepcionMercaderia {
            $existing = RecepcionMercaderia::query()->where('clave_idempotencia', $commandUuid)->lockForUpdate()->first();
            $createdAt = $existing?->created_at ?? CarbonImmutable::now('UTC');
            // A retry without a declared instant uses the original normal creation anchor.
            $instant = ($occurredAt ?? $createdAt)->setTimezone('UTC');
            $attributes = $this->headerAttributes(
                $actor, $origin, $supplierId, $contributorId, $originDetail,
                $instant, $createdAt, $occurrenceReason, $observation,
            );

            if ($existing !== null) {
                $this->assertCompatibleRetry($existing, $actor, $attributes);
                $this->assertDraft($existing);

                return $existing;
            }

            $receipt = new RecepcionMercaderia();
            $receipt->forceFill($attributes + [
                'clave_idempotencia' => $commandUuid,
                'usuario_creador_id' => (int) $actor->getKey(),
                'estado' => ReceiptStatus::Draft,
                'usuario_confirmador_id' => null,
                'usuario_anulador_id' => null,
                'confirmada_en' => null,
                'anulada_en' => null,
                'motivo_anulacion' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $receipt->save();

            return $receipt->refresh();
        });
    }

    public function updateDraft(
        User $actor,
        int $receiptId,
        ReceiptOriginType $origin,
        ?int $supplierId = null,
        ?int $contributorId = null,
        ?string $originDetail = null,
        ?CarbonImmutable $occurredAt = null,
        ?string $occurrenceReason = null,
        ?string $observation = null,
    ): RecepcionMercaderia {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsEdit);

        return DB::transaction(function () use (
            $actor, $receiptId, $origin, $supplierId, $contributorId, $originDetail,
            $occurredAt, $occurrenceReason, $observation,
        ): RecepcionMercaderia {
            $receipt = $this->lockDraft($actor, $receiptId);
            $receipt->forceFill($this->headerAttributes(
                $actor, $origin, $supplierId, $contributorId, $originDetail,
                ($occurredAt ?? $receipt->ocurrido_en)->setTimezone('UTC'), $receipt->created_at,
                $occurrenceReason, $observation,
            ));
            $receipt->save();

            return $receipt->refresh();
        });
    }

    public function addLine(
        User $actor,
        int $receiptId,
        int $articleId,
        ?string $quantity = null,
        ?string $unitCost = null,
        ?string $zeroCostReason = null,
    ): DetalleRecepcionMercaderia {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsEdit);

        return DB::transaction(function () use ($actor, $receiptId, $articleId, $quantity, $unitCost, $zeroCostReason): DetalleRecepcionMercaderia {
            $receipt = $this->lockDraft($actor, $receiptId);
            $line = new DetalleRecepcionMercaderia();
            $line->forceFill($this->lineAttributes(
                (int) $receipt->getKey(), $articleId, $quantity, $unitCost, $zeroCostReason,
            ) + ['recepcion_mercaderia_id' => (int) $receipt->getKey()]);
            $line->save();

            return $line->refresh();
        });
    }

    public function updateLine(
        User $actor,
        int $receiptId,
        int $detailId,
        int $articleId,
        ?string $quantity = null,
        ?string $unitCost = null,
        ?string $zeroCostReason = null,
    ): DetalleRecepcionMercaderia {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsEdit);

        return DB::transaction(function () use ($actor, $receiptId, $detailId, $articleId, $quantity, $unitCost, $zeroCostReason): DetalleRecepcionMercaderia {
            $receipt = $this->lockDraft($actor, $receiptId);
            $line = $this->lockLine($receipt, $detailId);
            $line->forceFill($this->lineAttributes(
                (int) $receipt->getKey(), $articleId, $quantity, $unitCost, $zeroCostReason, (int) $line->getKey(),
            ));
            $line->save();

            return $line->refresh();
        });
    }

    public function removeLine(User $actor, int $receiptId, int $detailId): void
    {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsEdit);

        DB::transaction(function () use ($actor, $receiptId, $detailId): void {
            $receipt = $this->lockDraft($actor, $receiptId);
            $this->lockLine($receipt, $detailId)->delete();
        });
    }

    public function deleteDraft(User $actor, int $receiptId): void
    {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsEdit);

        DB::transaction(function () use ($actor, $receiptId): void {
            $receipt = $this->lockDraft($actor, $receiptId);
            $lines = $receipt->detalles()->orderBy('id', 'asc')->lockForUpdate()->get();

            foreach ($lines as $line) {
                $line->delete();
            }

            $receipt->delete();
        });
    }

    public function confirm(
        User $actor,
        int $receiptId,
        string $commandUuid,
    ): RecepcionMercaderia {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsConfirm);
        $this->permissions->authorize($actor, PermissionCode::InventoryCostsRegister);
        $commandUuid = $this->validatedCommandUuid($commandUuid);
        $actorId = (int) $actor->getKey();

        return DB::transaction(function () use ($actor, $actorId, $receiptId, $commandUuid): RecepcionMercaderia {
            $receipt = $this->lockReceipt($receiptId);
            $lines = $receipt->detalles()->orderBy('id', 'asc')->lockForUpdate()->get();
            $articleIds = $this->articleIds($lines);
            $articles = $this->lockManager->lockArticles($articleIds);
            $elevated = $this->validateDeclarationForConfirmation($actor, $receipt);
            $authorizerId = $elevated ? $actorId : null;
            $movementReason = $receipt->ocurrido_en->lessThan($receipt->created_at)
                ? $receipt->motivo_ajuste_ocurrido_en
                : null;
            $existing = $this->lockCommandMovements($commandUuid);

            if ($receipt->estado === ReceiptStatus::Annulled) {
                throw new InventoryDomainException('Una recepcion anulada no puede confirmarse.');
            }

            if ($receipt->estado === ReceiptStatus::Confirmed) {
                if ($existing->isEmpty()) {
                    throw new InventoryDomainException('La recepcion ya fue confirmada con otro comando.');
                }

                $this->assertCompatibleConfirmationRetry(
                    $receipt,
                    $lines,
                    $existing,
                    $actorId,
                    $authorizerId,
                    $movementReason,
                );

                return $receipt->refresh();
            }

            if ($receipt->estado !== ReceiptStatus::Draft) {
                throw new InventoryDomainException('La recepcion no admite confirmacion en su estado actual.');
            }

            if ($existing->isNotEmpty()) {
                throw new IdempotencyConflictException('El UUID ya identifica otro comando de inventario.');
            }

            if ($lines->isEmpty()) {
                throw new InventoryDomainException('La recepcion requiere al menos una linea completa.');
            }

            $confirmedAt = CarbonImmutable::now('UTC');
            $mutations = [];
            $snapshots = [];
            $costRows = [];

            foreach ($lines as $line) {
                $articleId = $line->articulo_id;

                if (! is_int($articleId) || $articleId <= 0) {
                    throw new InventoryDomainException('Cada linea requiere un articulo valido.');
                }

                $article = $articles->get($articleId);

                if (! $article->activo) {
                    throw new InventoryDomainException('Todos los articulos deben estar activos al confirmar.');
                }

                [$quantity, $unitCost, $zeroCostReason] = $this->confirmedLineValues($line);
                $unit = $this->unitSnapshot($article);
                $snapshots[(int) $line->getKey()] = [
                    'articulo_sku_snapshot' => $article->sku,
                    'articulo_nombre_snapshot' => $article->nombre,
                    'unidad_codigo_snapshot' => $unit->codigo,
                    'unidad_simbolo_snapshot' => $unit->simbolo,
                ];
                $mutations[] = new InventoryStockMutation(
                    articleId: $articleId,
                    type: InventoryMovementType::Receipt,
                    delta: $quantity,
                    position: 1,
                    occurredAt: $receipt->ocurrido_en,
                    actorId: $actorId,
                    authorizerId: $authorizerId,
                    reason: $movementReason,
                    unitCode: $unit->codigo,
                    unitSymbol: $unit->simbolo,
                    saleDetailId: null,
                    receiptDetailId: (int) $line->getKey(),
                    productionDetailId: null,
                    productionConsumptionId: null,
                    wasteId: null,
                    inventoryCountDetailId: null,
                    reversedMovementId: null,
                );
                $costRows[] = [
                    'articulo_id' => $articleId,
                    'proveedor_id' => $receipt->tipo_origen === ReceiptOriginType::Supplier
                        ? $receipt->proveedor_id
                        : null,
                    'costo_anterior' => $this->previousValidCost($articleId, $receipt->ocurrido_en),
                    'costo_nuevo' => $unitCost,
                    'vigente_desde' => $receipt->ocurrido_en->format('Y-m-d H:i:s.uP'),
                    'usuario_id' => $actorId,
                    'motivo' => $zeroCostReason,
                    'created_at' => $confirmedAt,
                    'detalle_recepcion_mercaderia_id' => (int) $line->getKey(),
                ];
            }

            $simulation = $this->stockService->simulatePlan($articles, $commandUuid, $mutations);
            $this->stockService->applyPlan($simulation);

            if (! DB::table('historial_costos_articulo')->insert($costRows)) {
                throw new InventoryDomainException('No se pudo registrar el historial de costos de la recepcion.');
            }

            $this->rebuilder->rebuild($articleIds, CommercialDate::fromInstant($receipt->ocurrido_en));

            foreach ($lines as $line) {
                $line->forceFill($snapshots[(int) $line->getKey()]);
                $line->save();
            }

            $receipt->forceFill([
                'estado' => ReceiptStatus::Confirmed,
                'usuario_confirmador_id' => $actorId,
                'confirmada_en' => $confirmedAt,
                'usuario_anulador_id' => null,
                'anulada_en' => null,
                'motivo_anulacion' => null,
            ]);
            $receipt->save();

            return $receipt->refresh();
        });
    }

    public function annul(
        User $actor,
        int $receiptId,
        string $commandUuid,
        string $motivo,
    ): RecepcionMercaderia {
        $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsAnnul);
        $commandUuid = $this->validatedCommandUuid($commandUuid);
        $motivo = $this->requiredText($motivo, 'La anulacion requiere un motivo.');
        $actorId = (int) $actor->getKey();

        return DB::transaction(function () use ($actorId, $receiptId, $commandUuid, $motivo): RecepcionMercaderia {
            $receipt = $this->lockReceipt($receiptId);
            $lines = $receipt->detalles()->orderBy('id', 'asc')->lockForUpdate()->get();
            $articleIds = $this->articleIds($lines);
            $articles = $this->lockManager->lockArticles($articleIds);
            $detailIds = $lines->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $originals = MovimientoInventario::query()
                ->where('tipo_movimiento', InventoryMovementType::Receipt)
                ->whereIn('detalle_recepcion_mercaderia_id', $detailIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $existing = $this->lockCommandMovements($commandUuid);
            $originalIds = $originals->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $reversals = $originalIds === []
                ? MovimientoInventario::query()->whereRaw('1 = 0')->get()
                : MovimientoInventario::query()
                    ->whereIn('movimiento_revertido_id', $originalIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

            if ($receipt->estado === ReceiptStatus::Draft) {
                throw new InventoryDomainException('Una recepcion en borrador no puede anularse.');
            }

            if ($receipt->estado === ReceiptStatus::Annulled) {
                if ($existing->isEmpty()) {
                    throw new InventoryDomainException('La recepcion ya fue anulada con otro comando.');
                }

                $this->assertCompatibleAnnulmentRetry(
                    $receipt,
                    $lines,
                    $originals,
                    $reversals,
                    $existing,
                    $actorId,
                    $motivo,
                );

                return $receipt->refresh();
            }

            if ($receipt->estado !== ReceiptStatus::Confirmed) {
                throw new InventoryDomainException('La recepcion no admite anulacion en su estado actual.');
            }

            if ($existing->isNotEmpty()) {
                throw new IdempotencyConflictException('El UUID ya identifica otro comando de inventario.');
            }

            $this->assertReceiptOriginals($lines, $originals);

            if ($reversals->isNotEmpty()) {
                throw new InventoryDomainException('La recepcion contiene movimientos ya revertidos.');
            }

            $annulledAt = CarbonImmutable::now('UTC');
            $mutations = [];

            foreach ($this->orderedOriginalsByArticle($originals) as $original) {
                $articleId = $original->articulo_id;
                $article = $articles->get($articleId);
                $unit = $this->unitSnapshot($article);
                $mutations[] = new InventoryStockMutation(
                    articleId: $articleId,
                    type: InventoryMovementType::ReceiptReversal,
                    delta: Decimal::subtract('0.000', $original->cantidad_delta, 3),
                    position: 1,
                    occurredAt: $annulledAt,
                    actorId: $actorId,
                    authorizerId: null,
                    reason: $motivo,
                    unitCode: $unit->codigo,
                    unitSymbol: $unit->simbolo,
                    saleDetailId: null,
                    receiptDetailId: $original->detalle_recepcion_mercaderia_id,
                    productionDetailId: null,
                    productionConsumptionId: null,
                    wasteId: null,
                    inventoryCountDetailId: null,
                    reversedMovementId: (int) $original->getKey(),
                );
            }

            $simulation = $this->stockService->simulatePlan($articles, $commandUuid, $mutations);
            $this->stockService->applyPlan($simulation);
            $this->rebuilder->rebuild($articleIds, CommercialDate::fromInstant($annulledAt));
            $receipt->forceFill([
                'estado' => ReceiptStatus::Annulled,
                'usuario_anulador_id' => $actorId,
                'anulada_en' => $annulledAt,
                'motivo_anulacion' => $motivo,
            ]);
            $receipt->save();

            return $receipt->refresh();
        });
    }

    private function lockReceipt(int $receiptId): RecepcionMercaderia
    {
        $this->assertPositiveId($receiptId);

        return RecepcionMercaderia::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
    }

    private function articleIds($lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            if (! is_int($line->articulo_id) || $line->articulo_id <= 0) {
                throw new InventoryDomainException('Cada linea requiere un articulo valido.');
            }

            $ids[$line->articulo_id] = true;
        }

        ksort($ids, SORT_NUMERIC);

        return array_keys($ids);
    }

    private function validateDeclarationForConfirmation(
        User $actor,
        RecepcionMercaderia $receipt,
    ): bool {
        if (! $receipt->tipo_origen instanceof ReceiptOriginType
            || ! $receipt->ocurrido_en instanceof CarbonImmutable
            || ! $receipt->created_at instanceof CarbonImmutable) {
            throw new InventoryDomainException('La declaracion de la recepcion no es valida.');
        }

        match ($receipt->tipo_origen) {
            ReceiptOriginType::Supplier => $this->validateSupplierOrigin(
                $receipt->proveedor_id,
                $receipt->usuario_aportante_id,
                $receipt->origen_detalle,
            ),
            ReceiptOriginType::Internal => $this->validateInternalOrigin(
                $receipt->proveedor_id,
                $receipt->usuario_aportante_id,
                $receipt->origen_detalle,
            ),
            ReceiptOriginType::AuthorizedOther => $this->validateOtherOrigin(
                $receipt->proveedor_id,
                $receipt->usuario_aportante_id,
                $receipt->origen_detalle,
            ),
        };

        if ($receipt->ocurrido_en->greaterThan(CarbonImmutable::now('UTC'))
            || $receipt->ocurrido_en->greaterThan($receipt->created_at)) {
            throw new InvalidArgumentException('La ocurrencia no puede ser futura ni posterior a la creacion original.');
        }

        $otherOrigin = $receipt->tipo_origen === ReceiptOriginType::AuthorizedOther;
        $retroactive = $receipt->ocurrido_en->lessThan($receipt->created_at);

        if ($otherOrigin) {
            $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsOtherOrigin);
        }

        if ($retroactive) {
            $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsRetroactive);
            $this->requiredText(
                $receipt->motivo_ajuste_ocurrido_en,
                'La ocurrencia retroactiva requiere un motivo.',
            );
        } elseif ($receipt->motivo_ajuste_ocurrido_en !== null) {
            throw new InventoryDomainException('Una ocurrencia normal no admite motivo retroactivo.');
        }

        return $otherOrigin || $retroactive;
    }

    private function confirmedLineValues(DetalleRecepcionMercaderia $line): array
    {
        if (! is_string($line->cantidad) || ! is_string($line->costo_unitario)) {
            throw new InventoryDomainException('Todas las lineas requieren cantidad y costo.');
        }

        $quantity = Decimal::quantity($line->cantidad);
        $unitCost = Decimal::money($line->costo_unitario);

        if (Decimal::sign($quantity) <= 0 || Decimal::sign($unitCost) < 0) {
            throw new InventoryDomainException('La cantidad y el costo de cada linea deben ser validos.');
        }

        $zeroCost = Decimal::isZero($unitCost);

        if ($zeroCost) {
            $zeroCostReason = $this->requiredText(
                $line->motivo_costo_cero,
                'El costo cero requiere un motivo.',
            );
        } elseif ($line->motivo_costo_cero !== null) {
            throw new InventoryDomainException('Solo un costo cero admite motivo de costo cero.');
        } else {
            $zeroCostReason = null;
        }

        return [$quantity, $unitCost, $zeroCostReason];
    }

    private function unitSnapshot(Articulo $article): object
    {
        $unit = DB::table('unidades_medida')
            ->where('id', $article->unidad_id)
            ->first(['codigo', 'simbolo']);

        if ($unit === null) {
            throw new InventoryDomainException('El articulo requiere una unidad de medida existente.');
        }

        return $unit;
    }

    private function previousValidCost(int $articleId, CarbonImmutable $effectiveAt): ?string
    {
        $cost = DB::table('historial_costos_articulo as costos')
            ->leftJoin(
                'detalle_recepciones_mercaderia as detalles_costo',
                'detalles_costo.id',
                '=',
                'costos.detalle_recepcion_mercaderia_id',
            )
            ->leftJoin(
                'recepciones_mercaderia as recepciones_costo',
                'recepciones_costo.id',
                '=',
                'detalles_costo.recepcion_mercaderia_id',
            )
            ->where('costos.articulo_id', $articleId)
            ->where('costos.vigente_desde', '<=', $effectiveAt->format('Y-m-d H:i:s.uP'))
            ->where(static function ($query): void {
                $query->whereNull('costos.detalle_recepcion_mercaderia_id')
                    ->orWhere('recepciones_costo.estado', '<>', ReceiptStatus::Annulled->value);
            })
            ->orderByDesc('costos.vigente_desde')
            ->orderByDesc('costos.id')
            ->value('costos.costo_nuevo');

        return $cost === null ? null : Decimal::money((string) $cost);
    }

    private function lockCommandMovements(string $commandUuid)
    {
        return MovimientoInventario::query()
            ->where('clave_idempotencia', $commandUuid)
            ->orderBy('secuencia_idempotencia', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    private function assertCompatibleConfirmationRetry(
        RecepcionMercaderia $receipt,
        $lines,
        $movements,
        int $actorId,
        ?int $authorizerId,
        ?string $reason,
    ): void {
        if ($receipt->usuario_confirmador_id !== $actorId || $movements->count() !== $lines->count()) {
            throw new IdempotencyConflictException('El UUID no corresponde a esta confirmacion.');
        }

        $orderedLines = $lines->sort(static fn ($left, $right): int =>
            ($left->articulo_id <=> $right->articulo_id) ?: ($left->id <=> $right->id))->values();

        foreach ($orderedLines as $index => $line) {
            $movement = $movements[$index];

            if ($movement->tipo_movimiento !== InventoryMovementType::Receipt
                || $movement->articulo_id !== $line->articulo_id
                || $movement->usuario_id !== $actorId
                || $movement->usuario_autorizador_id !== $authorizerId
                || $movement->secuencia_idempotencia !== $index + 1
                || $movement->detalle_recepcion_mercaderia_id !== (int) $line->getKey()
                || $movement->movimiento_revertido_id !== null
                || $movement->motivo !== $reason
                || ! $movement->ocurrido_en->equalTo($receipt->ocurrido_en)
                || ! is_string($line->cantidad)
                || Decimal::compare($movement->cantidad_delta, $line->cantidad, 3) !== 0
                || ! $this->hasOnlyReceiptCause($movement)) {
                throw new IdempotencyConflictException('El UUID ya fue aplicado con una confirmacion incompatible.');
            }
        }
    }

    private function assertReceiptOriginals($lines, $originals): void
    {
        if ($lines->isEmpty() || $originals->count() !== $lines->count()) {
            throw new InventoryDomainException('Cada detalle requiere exactamente un movimiento original de recepcion.');
        }

        $byDetail = $originals->keyBy('detalle_recepcion_mercaderia_id');

        foreach ($lines as $line) {
            $original = $byDetail->get((int) $line->getKey());

            if (! $original instanceof MovimientoInventario
                || $original->tipo_movimiento !== InventoryMovementType::Receipt
                || $original->articulo_id !== $line->articulo_id
                || ! is_string($line->cantidad)
                || Decimal::compare($original->cantidad_delta, $line->cantidad, 3) !== 0
                || $original->movimiento_revertido_id !== null
                || ! $this->hasOnlyReceiptCause($original)) {
                throw new InventoryDomainException('El movimiento original no coincide con su detalle de recepcion.');
            }
        }
    }

    private function orderedOriginalsByArticle($originals): array
    {
        $ordered = $originals->all();
        usort($ordered, static fn (MovimientoInventario $left, MovimientoInventario $right): int =>
            ($left->articulo_id <=> $right->articulo_id) ?: ($left->id <=> $right->id));

        return $ordered;
    }

    private function assertCompatibleAnnulmentRetry(
        RecepcionMercaderia $receipt,
        $lines,
        $originals,
        $reversals,
        $movements,
        int $actorId,
        string $reason,
    ): void {
        try {
            $this->assertReceiptOriginals($lines, $originals);
        } catch (InventoryDomainException $exception) {
            throw new IdempotencyConflictException(
                'La estructura actual no corresponde a la anulacion identificada por el UUID.',
                previous: $exception,
            );
        }

        if ($receipt->usuario_anulador_id !== $actorId
            || $receipt->motivo_anulacion !== $reason
            || $movements->count() !== $originals->count()
            || $reversals->count() !== $originals->count()) {
            throw new IdempotencyConflictException('El UUID no corresponde a esta anulacion.');
        }

        $reversalIds = $reversals->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $movementIds = $movements->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        if ($reversalIds !== $movementIds) {
            throw new IdempotencyConflictException('El UUID no identifica todas las reversiones de la recepcion.');
        }

        foreach ($this->orderedOriginalsByArticle($originals) as $index => $original) {
            $movement = $movements[$index];

            if ($movement->tipo_movimiento !== InventoryMovementType::ReceiptReversal
                || $movement->articulo_id !== $original->articulo_id
                || $movement->usuario_id !== $actorId
                || $movement->usuario_autorizador_id !== null
                || $movement->secuencia_idempotencia !== $index + 1
                || $movement->detalle_recepcion_mercaderia_id !== $original->detalle_recepcion_mercaderia_id
                || $movement->movimiento_revertido_id !== (int) $original->getKey()
                || $movement->motivo !== $reason
                || ! $movement->ocurrido_en->equalTo($receipt->anulada_en)
                || Decimal::compare(
                    $movement->cantidad_delta,
                    Decimal::subtract('0.000', $original->cantidad_delta, 3),
                    3,
                ) !== 0
                || ! $this->hasOnlyReceiptCause($movement)) {
                throw new IdempotencyConflictException('El UUID ya fue aplicado con una anulacion incompatible.');
            }
        }
    }

    private function hasOnlyReceiptCause(MovimientoInventario $movement): bool
    {
        if ($movement->detalle_recepcion_mercaderia_id === null) {
            return false;
        }

        foreach ([
            'detalle_venta_id',
            'detalle_produccion_id',
            'consumo_produccion_id',
            'merma_id',
            'detalle_conteo_inventario_id',
        ] as $column) {
            if ($movement->getAttribute($column) !== null) {
                return false;
            }
        }

        return true;
    }

    private function validatedCommandUuid(string $commandUuid): string
    {
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('El comando debe tener un UUID valido.');
        }

        return strtolower($commandUuid);
    }

    private function lockDraft(User $actor, int $receiptId): RecepcionMercaderia
    {
        $this->assertPositiveId($receiptId);
        $receipt = RecepcionMercaderia::query()->whereKey($receiptId)->lockForUpdate()->firstOrFail();
        $this->assertDraft($receipt);
        $this->authorizeSpecialDeclaration($actor, $receipt->tipo_origen, $receipt->ocurrido_en, $receipt->created_at);

        return $receipt;
    }

    private function assertDraft(RecepcionMercaderia $receipt): void
    {
        if ($receipt->estado !== ReceiptStatus::Draft) {
            throw new InventoryDomainException('Solo una recepcion en borrador puede modificarse.');
        }
    }

    private function lockLine(RecepcionMercaderia $receipt, int $detailId): DetalleRecepcionMercaderia
    {
        $this->assertPositiveId($detailId);
        $line = DetalleRecepcionMercaderia::query()->whereKey($detailId)->lockForUpdate()->firstOrFail();

        if ($line->recepcion_mercaderia_id !== (int) $receipt->getKey()) {
            throw new InventoryDomainException('El detalle no pertenece a la recepcion indicada.');
        }

        return $line;
    }

    private function headerAttributes(
        User $actor,
        ReceiptOriginType $origin,
        ?int $supplierId,
        ?int $contributorId,
        ?string $originDetail,
        CarbonImmutable $occurredAt,
        CarbonImmutable $createdAt,
        ?string $occurrenceReason,
        ?string $observation,
    ): array {
        $this->authorizeSpecialDeclaration($actor, $origin, $occurredAt, $createdAt);

        match ($origin) {
            ReceiptOriginType::Supplier => $this->validateSupplierOrigin($supplierId, $contributorId, $originDetail),
            ReceiptOriginType::Internal => $this->validateInternalOrigin($supplierId, $contributorId, $originDetail),
            ReceiptOriginType::AuthorizedOther => $this->validateOtherOrigin($supplierId, $contributorId, $originDetail),
        };

        if ($occurredAt->greaterThan(CarbonImmutable::now('UTC')) || $occurredAt->greaterThan($createdAt)) {
            throw new InvalidArgumentException('La ocurrencia no puede ser futura ni posterior a la creacion original.');
        }

        $retroactive = $occurredAt->lessThan($createdAt);

        if (! $retroactive && $occurrenceReason !== null) {
            throw new InvalidArgumentException('Una ocurrencia normal no admite motivo retroactivo.');
        }

        return [
            'tipo_origen' => $origin,
            'proveedor_id' => $supplierId,
            'usuario_aportante_id' => $contributorId,
            'origen_detalle' => $origin === ReceiptOriginType::AuthorizedOther
                ? $this->requiredText($originDetail, 'El origen autorizado requiere un detalle.') : null,
            'ocurrido_en' => $occurredAt,
            'motivo_ajuste_ocurrido_en' => $retroactive
                ? $this->requiredText($occurrenceReason, 'La ocurrencia retroactiva requiere un motivo.') : null,
            'observacion' => $observation === null ? null : $this->requiredText($observation, 'La observacion no puede estar vacia.'),
        ];
    }

    private function authorizeSpecialDeclaration(
        User $actor,
        ReceiptOriginType $origin,
        CarbonImmutable $occurredAt,
        CarbonImmutable $createdAt,
    ): void {
        if ($origin === ReceiptOriginType::AuthorizedOther) {
            $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsOtherOrigin);
        }

        if ($occurredAt->lessThan($createdAt)) {
            $this->permissions->authorize($actor, PermissionCode::InventoryReceiptsRetroactive);
        }
    }

    private function validateSupplierOrigin(?int $supplierId, ?int $contributorId, ?string $originDetail): void
    {
        if ($supplierId === null || $contributorId !== null || $originDetail !== null) {
            throw new InvalidArgumentException('El origen proveedor requiere solo un proveedor.');
        }

        $this->assertReferenceExists('proveedores', $supplierId);
    }

    private function validateInternalOrigin(?int $supplierId, ?int $contributorId, ?string $originDetail): void
    {
        if ($supplierId !== null || $contributorId === null || $originDetail !== null) {
            throw new InvalidArgumentException('El origen interno requiere solo un usuario aportante.');
        }

        $this->assertReferenceExists('usuarios', $contributorId);
    }

    private function validateOtherOrigin(?int $supplierId, ?int $contributorId, ?string $originDetail): void
    {
        if ($supplierId !== null || $contributorId !== null) {
            throw new InvalidArgumentException('El otro origen autorizado no admite proveedor ni aportante.');
        }

        $this->requiredText($originDetail, 'El origen autorizado requiere un detalle.');
    }

    private function assertReferenceExists(string $table, int $id): void
    {
        $this->assertPositiveId($id);

        if (! DB::table($table)->where('id', $id)->exists()) {
            throw (new ModelNotFoundException())->setModel($table, [$id]);
        }
    }

    private function assertCompatibleRetry(RecepcionMercaderia $receipt, User $actor, array $attributes): void
    {
        if ($receipt->usuario_creador_id !== (int) $actor->getKey()) {
            throw new IdempotencyConflictException('El UUID corresponde a otro actor.');
        }

        foreach ($attributes as $column => $expected) {
            $actual = $receipt->getAttribute($column);
            $matches = $expected instanceof CarbonImmutable
                ? $actual instanceof CarbonImmutable && $actual->equalTo($expected)
                : $actual === $expected;

            if (! $matches) {
                throw new IdempotencyConflictException('El UUID ya identifica un borrador con payload incompatible.');
            }
        }
    }

    private function lineAttributes(
        int $receiptId,
        int $articleId,
        ?string $quantity,
        ?string $unitCost,
        ?string $zeroCostReason,
        ?int $excludedDetailId = null,
    ): array {
        $this->assertPositiveId($articleId);
        $article = Articulo::query()->findOrFail($articleId);

        if (! $article->activo) {
            throw new InventoryDomainException('El articulo esta inactivo; debe reactivarse antes de recibirlo.');
        }

        $duplicate = DetalleRecepcionMercaderia::query()
            ->where('recepcion_mercaderia_id', $receiptId)->where('articulo_id', $articleId);

        if ($excludedDetailId !== null) {
            $duplicate->where('id', '<>', $excludedDetailId);
        }

        if ($duplicate->exists()) {
            throw new InventoryDomainException('El articulo ya tiene una linea en esta recepcion.');
        }

        $quantity = $quantity === null ? null : Decimal::quantity($quantity);
        $unitCost = $unitCost === null ? null : Decimal::money($unitCost);

        if ($quantity !== null && Decimal::sign($quantity) <= 0) {
            throw new InvalidArgumentException('La cantidad informada debe ser positiva.');
        }

        if ($unitCost !== null && Decimal::sign($unitCost) < 0) {
            throw new InvalidArgumentException('El costo informado no puede ser negativo.');
        }

        $zeroCost = $unitCost !== null && Decimal::isZero($unitCost);

        if (! $zeroCost && $zeroCostReason !== null) {
            throw new InvalidArgumentException('Solo un costo cero admite motivo de costo cero.');
        }

        $reason = $zeroCost ? $this->requiredText($zeroCostReason, 'El costo cero requiere un motivo.') : null;
        $unit = DB::table('unidades_medida')->where('id', $article->unidad_id)->first(['codigo', 'simbolo']);

        if ($unit === null) {
            throw new InventoryDomainException('El articulo requiere una unidad de medida existente.');
        }

        // Draft identity snapshots satisfy NOT NULL; physical confirmation must refresh them.
        return [
            'articulo_id' => $articleId,
            'articulo_sku_snapshot' => $article->sku,
            'articulo_nombre_snapshot' => $article->nombre,
            'unidad_codigo_snapshot' => $unit->codigo,
            'unidad_simbolo_snapshot' => $unit->simbolo,
            'cantidad' => $quantity,
            'costo_unitario' => $unitCost,
            'motivo_costo_cero' => $reason,
        ];
    }

    private function requiredText(?string $value, string $message): string
    {
        $value = trim($value ?? '');

        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function assertPositiveId(int $id): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El ID debe ser un entero positivo.');
        }
    }
}
