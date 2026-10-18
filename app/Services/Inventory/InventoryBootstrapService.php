<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryMovementData;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Support\CommercialDate;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InventoryBootstrapService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryLedgerWriter $ledgerWriter,
        private readonly StockDailyRebuilder $rebuilder,
    ) {
    }

    public function initialize(
        User $actor,
        int $articleId,
        string $commandUuid,
        string $motivo,
    ): ?MovimientoInventario {
        $this->permissions->authorize($actor, PermissionCode::InventoryInitialBalanceExecute);

        if ($articleId <= 0) {
            throw new InvalidArgumentException('El articulo debe tener un ID positivo.');
        }

        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('El comando debe tener un UUID valido.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new InvalidArgumentException('El saldo inicial requiere un motivo no vacio.');
        }

        $commandUuid = strtolower($commandUuid);
        $actorId = (int) $actor->getKey();

        return DB::transaction(function () use ($articleId, $commandUuid, $motivo, $actorId): ?MovimientoInventario {
            $article = $this->lockManager->lockArticles([$articleId])->get($articleId);
            $existing = MovimientoInventario::query()
                ->where('clave_idempotencia', $commandUuid)
                ->orderBy('secuencia_idempotencia', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($existing->isNotEmpty()) {
                if ($existing->count() !== 1) {
                    throw new IdempotencyConflictException('El UUID debe identificar un unico saldo inicial.');
                }

                $movement = $existing->first();
                $this->assertCompatibleRetry($movement, $articleId, $actorId, $motivo);

                return $movement;
            }

            if (MovimientoInventario::query()->where('articulo_id', $articleId)->exists()) {
                throw new InventoryDomainException('El articulo ya tiene historial y no admite un saldo inicial nuevo.');
            }

            $rawStock = $article->getRawOriginal('stock_actual');

            if (! is_string($rawStock)) {
                throw new InvalidArgumentException('El stock bloqueado debe ser una cantidad decimal string.');
            }

            $stock = Decimal::quantity($rawStock);

            if (Decimal::sign($stock) < 0) {
                throw new InventoryDomainException('El stock inicial no puede ser negativo.');
            }

            if (Decimal::isZero($stock)) {
                return null;
            }

            $unit = DB::table('unidades_medida')
                ->where('id', $article->getAttribute('unidad_id'))
                ->first(['codigo', 'simbolo']);

            if ($unit === null) {
                throw new InventoryDomainException('El articulo requiere una unidad de medida existente.');
            }

            $instant = CarbonImmutable::now('UTC');
            $movement = $this->ledgerWriter->append(new InventoryMovementData(
                article: $article,
                type: InventoryMovementType::InitialBalance,
                delta: $stock,
                stockBefore: '0.000',
                stockAfter: $stock,
                commandUuid: $commandUuid,
                sequence: 1,
                occurredAt: $instant,
                actorId: $actorId,
                authorizerId: $actorId,
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
            ));
            $this->rebuilder->rebuild([$articleId], CommercialDate::fromInstant($instant));

            return $movement;
        });
    }

    private function assertCompatibleRetry(
        MovimientoInventario $movement,
        int $articleId,
        int $actorId,
        string $motivo,
    ): void {
        // Original execution evidence is independent of later stock or retry time.
        if ($movement->tipo_movimiento !== InventoryMovementType::InitialBalance
            || $movement->articulo_id !== $articleId
            || $movement->usuario_id !== $actorId
            || $movement->usuario_autorizador_id !== $actorId
            || $movement->motivo !== $motivo
            || $movement->secuencia_idempotencia !== 1
            || ! Decimal::isZero($movement->stock_anterior)
            || Decimal::sign($movement->cantidad_delta) <= 0
            || Decimal::compare($movement->cantidad_delta, $movement->stock_posterior, 3) !== 0) {
            throw new IdempotencyConflictException('El UUID ya fue aplicado con un payload incompatible.');
        }

        foreach ([
            'detalle_venta_id', 'detalle_recepcion_mercaderia_id', 'detalle_produccion_id',
            'consumo_produccion_id', 'merma_id', 'detalle_conteo_inventario_id', 'movimiento_revertido_id',
        ] as $column) {
            if ($movement->getAttribute($column) !== null) {
                throw new IdempotencyConflictException('El UUID no corresponde a un saldo inicial sin causales.');
            }
        }
    }
}
