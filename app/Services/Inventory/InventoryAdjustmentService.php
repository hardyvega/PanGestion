<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InventoryAdjustmentService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stockService,
        private readonly StockDailyRebuilder $rebuilder,
    ) {
    }

    public function manualEntry(
        User $actor,
        int $articleId,
        string $quantity,
        string $commandUuid,
        string $motivo,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        return $this->adjust(
            $actor,
            $articleId,
            $quantity,
            $commandUuid,
            $motivo,
            $occurredAt,
            InventoryMovementType::ManualInput,
            PermissionCode::InventoryAdjustmentsInput,
        );
    }

    public function manualExit(
        User $actor,
        int $articleId,
        string $quantity,
        string $commandUuid,
        string $motivo,
        ?CarbonImmutable $occurredAt = null,
    ): MovimientoInventario {
        return $this->adjust(
            $actor,
            $articleId,
            $quantity,
            $commandUuid,
            $motivo,
            $occurredAt,
            InventoryMovementType::ManualOutput,
            PermissionCode::InventoryAdjustmentsOutput,
        );
    }

    private function adjust(
        User $actor,
        int $articleId,
        string $quantity,
        string $commandUuid,
        string $motivo,
        ?CarbonImmutable $occurredAt,
        InventoryMovementType $type,
        PermissionCode $permission,
    ): MovimientoInventario {
        return DB::transaction(function () use (
            $actor,
            $articleId,
            $quantity,
            $commandUuid,
            $motivo,
            $occurredAt,
            $type,
            $permission,
        ): MovimientoInventario {
            $this->permissions->authorize($actor, $permission);

            if ($articleId <= 0) {
                throw new InvalidArgumentException('El articulo debe tener un ID positivo.');
            }

            $quantity = Decimal::quantity($quantity);

            if (Decimal::sign($quantity) <= 0) {
                throw new InvalidArgumentException('La cantidad del ajuste debe ser positiva.');
            }

            if (! Str::isUuid($commandUuid)) {
                throw new InvalidArgumentException('El comando debe tener un UUID valido.');
            }

            $commandUuid = strtolower($commandUuid);
            $motivo = trim($motivo);

            if ($motivo === '') {
                throw new InvalidArgumentException('El ajuste manual requiere un motivo no vacio.');
            }

            $now = CarbonImmutable::now('UTC');
            $explicitOccurredAt = $occurredAt?->setTimezone('UTC');

            if ($explicitOccurredAt?->greaterThan($now)) {
                throw new InvalidArgumentException('El ajuste manual no puede ocurrir en el futuro.');
            }

            $retroactive = $explicitOccurredAt?->lessThan($now) ?? false;

            if ($retroactive) {
                $this->permissions->authorize($actor, PermissionCode::InventoryAdjustmentsRetroactive);
            }

            $existing = MovimientoInventario::query()
                ->where('clave_idempotencia', $commandUuid)
                ->orderBy('secuencia_idempotencia', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
            $locked = $this->lockManager->lockArticles([$articleId]);
            $article = $locked->get($articleId);

            if ($type === InventoryMovementType::ManualInput && ! $article->activo) {
                throw new InventoryDomainException('Un articulo inactivo no admite ajustes manuales de entrada.');
            }

            $actorId = (int) $actor->getKey();
            $authorizerId = $retroactive ? $actorId : null;
            $instant = $explicitOccurredAt ?? $now;
            $delta = $type === InventoryMovementType::ManualInput
                ? $quantity
                : Decimal::subtract('0.000', $quantity, 3);

            if ($existing->isNotEmpty()) {
                return $this->compatibleRetry(
                    $existing->all(),
                    $articleId,
                    $actorId,
                    $authorizerId,
                    $type,
                    $delta,
                    $motivo,
                    $explicitOccurredAt,
                );
            }

            $unit = $this->unitSnapshot($article);
            $simulation = $this->stockService->simulatePlan($locked, $commandUuid, [
                new InventoryStockMutation(
                    articleId: $articleId,
                    type: $type,
                    delta: $delta,
                    position: 1,
                    occurredAt: $instant,
                    actorId: $actorId,
                    authorizerId: $authorizerId,
                    reason: $motivo,
                    unitCode: $unit->codigo,
                    unitSymbol: $unit->simbolo,
                    saleDetailId: null,
                    receiptDetailId: null,
                    productionDetailId: null,
                    productionConsumptionId: null,
                    wasteId: null,
                    inventoryCountDetailId: null,
                    reversedMovementId: null,
                ),
            ]);
            $movements = $this->stockService->applyPlan($simulation);

            if (count($movements) !== 1) {
                throw new InventoryDomainException('El ajuste manual debe producir un unico movimiento.');
            }

            $movement = $movements[0];
            $this->rebuilder->rebuild([$articleId], CommercialDate::fromInstant($movement->ocurrido_en));

            return $movement;
        });
    }

    /** @param array<MovimientoInventario> $existing */
    private function compatibleRetry(
        array $existing,
        int $articleId,
        int $actorId,
        ?int $authorizerId,
        InventoryMovementType $type,
        string $delta,
        string $motivo,
        ?CarbonImmutable $explicitOccurredAt,
    ): MovimientoInventario {
        if (count($existing) !== 1) {
            throw new IdempotencyConflictException('El UUID no identifica un ajuste manual individual.');
        }

        $movement = $existing[0];

        if ($movement->tipo_movimiento !== $type
            || $movement->articulo_id !== $articleId
            || $movement->usuario_id !== $actorId
            || $movement->usuario_autorizador_id !== $authorizerId
            || $movement->secuencia_idempotencia !== 1
            || $movement->motivo !== $motivo
            || Decimal::compare($movement->cantidad_delta, $delta, 3) !== 0
            || ($explicitOccurredAt !== null && ! $movement->ocurrido_en->equalTo($explicitOccurredAt))) {
            throw new IdempotencyConflictException('El UUID ya fue aplicado con un ajuste manual incompatible.');
        }

        foreach ([
            'detalle_venta_id',
            'detalle_recepcion_mercaderia_id',
            'detalle_produccion_id',
            'consumo_produccion_id',
            'merma_id',
            'detalle_conteo_inventario_id',
            'movimiento_revertido_id',
        ] as $column) {
            if ($movement->getAttribute($column) !== null) {
                throw new IdempotencyConflictException('El UUID no corresponde a un ajuste manual sin causales.');
            }
        }

        return $movement;
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
}
