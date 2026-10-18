<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Exceptions\Inventory\InventoryMovementAlreadyReversedException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class WasteService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stockService,
        private readonly StockDailyRebuilder $rebuilder,
    ) {
    }

    public function record(
        User $actor,
        int $articleId,
        string $quantity,
        string $commandUuid,
        string $motivo,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        return DB::transaction(function () use (
            $actor,
            $articleId,
            $quantity,
            $commandUuid,
            $motivo,
            $occurredAt,
        ): MovimientoInventario {
            $this->permissions->authorize($actor, PermissionCode::InventoryWasteRegister);
            $this->assertPositiveId($articleId, 'El articulo debe tener un ID positivo.');
            $quantity = Decimal::quantity($quantity);

            if (Decimal::sign($quantity) <= 0) {
                throw new InvalidArgumentException('La cantidad de la merma debe ser positiva.');
            }

            $commandUuid = $this->validatedCommandUuid($commandUuid);
            $motivo = $this->requiredText($motivo, 'La merma requiere un motivo no vacio.');
            $now = CarbonImmutable::now('UTC');
            $explicitOccurredAt = $occurredAt?->setTimezone('UTC');

            if ($explicitOccurredAt?->greaterThan($now)) {
                throw new InvalidArgumentException('La merma no puede ocurrir en el futuro.');
            }

            $retroactive = $explicitOccurredAt?->lessThan($now) ?? false;

            if ($retroactive) {
                $this->permissions->authorize($actor, PermissionCode::InventoryWasteRetroactive);
            }

            $actorId = (int) $actor->getKey();
            $authorizerId = $retroactive ? $actorId : null;
            $instant = $explicitOccurredAt ?? $now;
            $delta = Decimal::subtract('0.000', $quantity, 3);
            $existingWaste = DB::table('mermas')
                ->where('clave_idempotencia', $commandUuid)
                ->lockForUpdate()
                ->first();
            $existingMovements = $this->lockCommandMovements($commandUuid);

            if ($existingWaste !== null) {
                return $this->compatibleRecordRetry(
                    $existingWaste,
                    $existingMovements,
                    $articleId,
                    $actorId,
                    $authorizerId,
                    $quantity,
                    $delta,
                    $motivo,
                    $retroactive,
                    $explicitOccurredAt,
                );
            }

            if ($existingMovements->isNotEmpty()) {
                throw new IdempotencyConflictException('El UUID ya identifica otro comando de inventario.');
            }

            $locked = $this->lockManager->lockArticles([$articleId]);
            $article = $locked->get($articleId);
            $unit = $this->unitSnapshot($article);

            $this->stockService->simulatePlan($locked, $commandUuid, [
                $this->wasteMutation(
                    $articleId,
                    $delta,
                    $instant,
                    $actorId,
                    $authorizerId,
                    $motivo,
                    $unit,
                    null,
                ),
            ]);

            $wasteId = (int) DB::table('mermas')->insertGetId([
                'articulo_id' => $articleId,
                'usuario_registrador_id' => $actorId,
                'usuario_anulador_id' => null,
                'merma_reemplazada_id' => null,
                'clave_idempotencia' => $commandUuid,
                'articulo_sku_snapshot' => $article->sku,
                'articulo_nombre_snapshot' => $article->nombre,
                'unidad_codigo_snapshot' => $unit->codigo,
                'unidad_simbolo_snapshot' => $unit->simbolo,
                'tipo_merma' => 'otro',
                'motivo_detalle' => $motivo,
                'cantidad' => $quantity,
                'ocurrido_en' => $this->timestamp($instant),
                'motivo_ajuste_ocurrido_en' => $retroactive ? $motivo : null,
                'anulada_en' => null,
                'motivo_anulacion' => null,
                'created_at' => $this->timestamp($now),
            ]);
            $simulation = $this->stockService->simulatePlan($locked, $commandUuid, [
                $this->wasteMutation(
                    $articleId,
                    $delta,
                    $instant,
                    $actorId,
                    $authorizerId,
                    $motivo,
                    $unit,
                    $wasteId,
                ),
            ]);
            $movements = $this->stockService->applyPlan($simulation);

            if (count($movements) !== 1) {
                throw new InventoryDomainException('La merma debe producir un unico movimiento.');
            }

            $movement = $movements[0];
            $this->rebuilder->rebuild([$articleId], CommercialDate::fromInstant($movement->ocurrido_en));

            return $movement;
        });
    }

    public function annul(
        User $actor,
        int $wasteId,
        string $commandUuid,
        string $motivo,
    ): MovimientoInventario {
        return DB::transaction(function () use ($actor, $wasteId, $commandUuid, $motivo): MovimientoInventario {
            $this->permissions->authorize($actor, PermissionCode::InventoryWasteAnnul);
            $this->assertPositiveId($wasteId, 'La merma debe tener un ID positivo.');
            $commandUuid = $this->validatedCommandUuid($commandUuid);
            $motivo = $this->requiredText($motivo, 'La anulacion de merma requiere un motivo no vacio.');
            $actorId = (int) $actor->getKey();
            $waste = DB::table('mermas')->where('id', $wasteId)->lockForUpdate()->first();

            if ($waste === null) {
                throw (new ModelNotFoundException())->setModel('mermas', [$wasteId]);
            }

            $articleId = (int) $waste->articulo_id;
            $locked = $this->lockManager->lockArticles([$articleId]);
            $article = $locked->get($articleId);
            $originals = MovimientoInventario::query()
                ->where('tipo_movimiento', InventoryMovementType::Waste)
                ->where('merma_id', $wasteId)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $existingMovements = $this->lockCommandMovements($commandUuid);
            $originalIds = $originals->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $reversals = $originalIds === []
                ? MovimientoInventario::query()->whereRaw('1 = 0')->get()
                : MovimientoInventario::query()
                    ->whereIn('movimiento_revertido_id', $originalIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();
            $annulledFields = array_filter([
                $waste->usuario_anulador_id,
                $waste->anulada_en,
                $waste->motivo_anulacion,
            ], static fn ($value): bool => $value !== null);

            if (count($annulledFields) !== 0 && count($annulledFields) !== 3) {
                throw new InventoryDomainException('La auditoria de anulacion de la merma es inconsistente.');
            }

            if (count($annulledFields) === 3) {
                if ($existingMovements->isEmpty()) {
                    throw new InventoryMovementAlreadyReversedException('La merma ya fue anulada con otro comando.');
                }

                return $this->compatibleAnnulRetry(
                    $waste,
                    $originals,
                    $reversals,
                    $existingMovements,
                    $actorId,
                    $motivo,
                );
            }

            if ($existingMovements->isNotEmpty()) {
                throw new IdempotencyConflictException('El UUID ya identifica otro comando de inventario.');
            }

            $original = $this->assertOriginalMovement($waste, $originals);

            if ($reversals->isNotEmpty()) {
                throw new InventoryMovementAlreadyReversedException('El movimiento de merma ya fue revertido.');
            }

            $annulledAt = CarbonImmutable::now('UTC');
            $unit = $this->unitSnapshot($article);
            $delta = Decimal::subtract('0.000', $original->cantidad_delta, 3);
            $simulation = $this->stockService->simulatePlan($locked, $commandUuid, [
                new InventoryStockMutation(
                    articleId: $articleId,
                    type: InventoryMovementType::WasteReversal,
                    delta: $delta,
                    position: 1,
                    occurredAt: $annulledAt,
                    actorId: $actorId,
                    authorizerId: null,
                    reason: $motivo,
                    unitCode: $unit->codigo,
                    unitSymbol: $unit->simbolo,
                    saleDetailId: null,
                    receiptDetailId: null,
                    productionDetailId: null,
                    productionConsumptionId: null,
                    wasteId: $wasteId,
                    inventoryCountDetailId: null,
                    reversedMovementId: (int) $original->getKey(),
                ),
            ]);
            $movements = $this->stockService->applyPlan($simulation);

            if (count($movements) !== 1) {
                throw new InventoryDomainException('La anulacion de merma debe producir un unico movimiento.');
            }

            $movement = $movements[0];
            $this->rebuilder->rebuild([$articleId], CommercialDate::fromInstant($annulledAt));
            $updated = DB::table('mermas')
                ->where('id', $wasteId)
                ->whereNull('usuario_anulador_id')
                ->whereNull('anulada_en')
                ->whereNull('motivo_anulacion')
                ->update([
                    'usuario_anulador_id' => $actorId,
                    'anulada_en' => $this->timestamp($annulledAt),
                    'motivo_anulacion' => $motivo,
                ]);

            if ($updated !== 1) {
                throw new InventoryDomainException('No se pudo persistir la anulacion de la merma.');
            }

            return $movement;
        });
    }

    private function wasteMutation(
        int $articleId,
        string $delta,
        CarbonImmutable $occurredAt,
        int $actorId,
        ?int $authorizerId,
        string $reason,
        object $unit,
        ?int $wasteId,
    ): InventoryStockMutation {
        return new InventoryStockMutation(
            articleId: $articleId,
            type: InventoryMovementType::Waste,
            delta: $delta,
            position: 1,
            occurredAt: $occurredAt,
            actorId: $actorId,
            authorizerId: $authorizerId,
            reason: $reason,
            unitCode: $unit->codigo,
            unitSymbol: $unit->simbolo,
            saleDetailId: null,
            receiptDetailId: null,
            productionDetailId: null,
            productionConsumptionId: null,
            wasteId: $wasteId,
            inventoryCountDetailId: null,
            reversedMovementId: null,
        );
    }

    private function compatibleRecordRetry(
        object $waste,
        $movements,
        int $articleId,
        int $actorId,
        ?int $authorizerId,
        string $quantity,
        string $delta,
        string $reason,
        bool $retroactive,
        ?CarbonImmutable $explicitOccurredAt,
    ): MovimientoInventario {
        if ($movements->count() !== 1) {
            throw new IdempotencyConflictException('El UUID no identifica una merma individual.');
        }

        $movement = $movements->first();
        $storedOccurredAt = CarbonImmutable::parse((string) $waste->ocurrido_en)->setTimezone('UTC');
        $storedCreatedAt = CarbonImmutable::parse((string) $waste->created_at)->setTimezone('UTC');
        $storedRetroactive = $waste->motivo_ajuste_ocurrido_en !== null;

        if ((int) $waste->articulo_id !== $articleId
            || (int) $waste->usuario_registrador_id !== $actorId
            || $waste->merma_reemplazada_id !== null
            || $waste->tipo_merma !== 'otro'
            || $waste->motivo_detalle !== $reason
            || Decimal::compare((string) $waste->cantidad, $quantity, 3) !== 0
            || $storedRetroactive !== $retroactive
            || ($retroactive && $waste->motivo_ajuste_ocurrido_en !== $reason)
            || (! $retroactive && $waste->motivo_ajuste_ocurrido_en !== null)
            || ($retroactive && ! $storedOccurredAt->lessThan($storedCreatedAt))
            || (! $retroactive && ! $storedOccurredAt->equalTo($storedCreatedAt))
            || ! $movement instanceof MovimientoInventario
            || $movement->tipo_movimiento !== InventoryMovementType::Waste
            || $movement->articulo_id !== $articleId
            || $movement->usuario_id !== $actorId
            || $movement->usuario_autorizador_id !== $authorizerId
            || $movement->secuencia_idempotencia !== 1
            || $movement->motivo !== $reason
            || $movement->merma_id !== (int) $waste->id
            || $movement->movimiento_revertido_id !== null
            || Decimal::compare($movement->cantidad_delta, $delta, 3) !== 0
            || ! $movement->ocurrido_en->equalTo($storedOccurredAt)
            || ($explicitOccurredAt !== null && ! $movement->ocurrido_en->equalTo($explicitOccurredAt))
            || ! $this->hasOnlyWasteCause($movement)) {
            throw new IdempotencyConflictException('El UUID ya fue aplicado con una merma incompatible.');
        }

        return $movement;
    }

    private function compatibleAnnulRetry(
        object $waste,
        $originals,
        $reversals,
        $movements,
        int $actorId,
        string $reason,
    ): MovimientoInventario {
        try {
            $original = $this->assertOriginalMovement($waste, $originals);
        } catch (InventoryDomainException $exception) {
            throw new IdempotencyConflictException(
                'La estructura actual no corresponde a la anulacion identificada por el UUID.',
                previous: $exception,
            );
        }

        if ($movements->count() !== 1
            || $reversals->count() !== 1
            || (int) $waste->usuario_anulador_id !== $actorId
            || $waste->motivo_anulacion !== $reason) {
            throw new IdempotencyConflictException('El UUID no corresponde a esta anulacion de merma.');
        }

        $movement = $movements->first();
        $reversal = $reversals->first();
        $annulledAt = CarbonImmutable::parse((string) $waste->anulada_en)->setTimezone('UTC');

        if (! $movement instanceof MovimientoInventario
            || ! $reversal instanceof MovimientoInventario
            || $movement->getKey() !== $reversal->getKey()
            || $movement->tipo_movimiento !== InventoryMovementType::WasteReversal
            || $movement->articulo_id !== (int) $waste->articulo_id
            || $movement->usuario_id !== $actorId
            || $movement->usuario_autorizador_id !== null
            || $movement->secuencia_idempotencia !== 1
            || $movement->motivo !== $reason
            || $movement->merma_id !== (int) $waste->id
            || $movement->movimiento_revertido_id !== (int) $original->getKey()
            || ! $movement->ocurrido_en->equalTo($annulledAt)
            || Decimal::compare(
                $movement->cantidad_delta,
                Decimal::subtract('0.000', $original->cantidad_delta, 3),
                3,
            ) !== 0
            || ! $this->hasOnlyWasteCause($movement)) {
            throw new IdempotencyConflictException('El UUID ya fue aplicado con una anulacion incompatible.');
        }

        return $movement;
    }

    private function assertOriginalMovement(object $waste, $originals): MovimientoInventario
    {
        if ($originals->count() !== 1) {
            throw new InventoryDomainException('La merma requiere exactamente un movimiento original.');
        }

        $original = $originals->first();
        $storedOccurredAt = CarbonImmutable::parse((string) $waste->ocurrido_en)->setTimezone('UTC');
        $expectedAuthorizerId = $waste->motivo_ajuste_ocurrido_en === null
            ? null
            : (int) $waste->usuario_registrador_id;

        if (! $original instanceof MovimientoInventario
            || $original->tipo_movimiento !== InventoryMovementType::Waste
            || $original->articulo_id !== (int) $waste->articulo_id
            || $original->usuario_id !== (int) $waste->usuario_registrador_id
            || $original->usuario_autorizador_id !== $expectedAuthorizerId
            || $original->secuencia_idempotencia !== 1
            || $original->clave_idempotencia !== strtolower((string) $waste->clave_idempotencia)
            || $original->motivo !== $waste->motivo_detalle
            || $original->merma_id !== (int) $waste->id
            || $original->movimiento_revertido_id !== null
            || Decimal::compare(
                $original->cantidad_delta,
                Decimal::subtract('0.000', (string) $waste->cantidad, 3),
                3,
            ) !== 0
            || ! $original->ocurrido_en->equalTo($storedOccurredAt)
            || ! $this->hasOnlyWasteCause($original)) {
            throw new InventoryDomainException('El movimiento original no coincide con la merma.');
        }

        return $original;
    }

    private function hasOnlyWasteCause(MovimientoInventario $movement): bool
    {
        if ($movement->merma_id === null) {
            return false;
        }

        foreach ([
            'detalle_venta_id',
            'detalle_recepcion_mercaderia_id',
            'detalle_produccion_id',
            'consumo_produccion_id',
            'detalle_conteo_inventario_id',
        ] as $column) {
            if ($movement->getAttribute($column) !== null) {
                return false;
            }
        }

        return true;
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

    private function validatedCommandUuid(string $commandUuid): string
    {
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('El comando debe tener un UUID valido.');
        }

        return strtolower($commandUuid);
    }

    private function requiredText(string $value, string $message): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private function assertPositiveId(int $id, string $message): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException($message);
        }
    }

    private function timestamp(CarbonImmutable $instant): string
    {
        return $instant->setTimezone('UTC')->format('Y-m-d H:i:s.uP');
    }
}
