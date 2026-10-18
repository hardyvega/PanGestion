<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryMovementData;
use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Exceptions\Inventory\InventoryMovementAlreadyReversedException;
use App\Models\MovimientoInventario;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;

final class InventoryLedgerWriter
{
    public function append(InventoryMovementData $data): MovimientoInventario
    {
        $this->assertTransaction($data);
        $attributes = $this->validatedAttributes($data);

        if ($data->type->isReversal()) {
            $original = (new MovimientoInventario())
                ->setConnection($data->article->getConnectionName())
                ->newQuery()
                ->findOrFail($data->reversedMovementId);
            $this->assertReversal($original, $data);
        }

        return $this->persist($data, $attributes);
    }

    public function reverseLocked(
        MovimientoInventario $original,
        InventoryMovementData $data,
    ): MovimientoInventario {
        $this->assertTransaction($data);
        $attributes = $this->validatedAttributes($data);
        $this->assertReversal($original, $data);

        return $this->persist($data, $attributes);
    }

    // Shared preflight lets the stock service validate every row before any write.
    public function validatedAttributes(InventoryMovementData $data): array
    {
        $delta = Decimal::quantity($data->delta);
        $before = Decimal::quantity($data->stockBefore);
        $after = Decimal::quantity($data->stockAfter);

        if (Decimal::isZero($delta)
            || ($data->type->isPositive() && Decimal::sign($delta) !== 1)
            || ($data->type->isNegative() && Decimal::sign($delta) !== -1)
            || Decimal::sign($before) < 0
            || Decimal::sign($after) < 0
            || Decimal::compare(Decimal::add($before, $delta, 3), $after, 3) !== 0) {
            throw new InventoryDomainException('El movimiento no tiene cantidades y stocks coherentes.');
        }

        $articleId = $data->article->getAttributes()[$data->article->getKeyName()] ?? null;

        if (! is_int($articleId) || $articleId <= 0
            || $data->actorId <= 0
            || ($data->authorizerId !== null && $data->authorizerId <= 0)
            || ! Str::isUuid($data->commandUuid)
            || $data->sequence <= 0 || $data->sequence > 2147483647) {
            throw new InventoryDomainException('El movimiento requiere IDs, UUID y secuencia validos.');
        }

        $causes = [
            'detalle_venta_id' => $data->saleDetailId,
            'detalle_recepcion_mercaderia_id' => $data->receiptDetailId,
            'detalle_produccion_id' => $data->productionDetailId,
            'consumo_produccion_id' => $data->productionConsumptionId,
            'merma_id' => $data->wasteId,
            'detalle_conteo_inventario_id' => $data->inventoryCountDetailId,
        ];
        $causeType = $data->type->originalType() ?? $data->type;
        $causeColumn = match ($causeType) {
            InventoryMovementType::Sale => 'detalle_venta_id',
            InventoryMovementType::Receipt => 'detalle_recepcion_mercaderia_id',
            InventoryMovementType::ProductionInput => 'detalle_produccion_id',
            InventoryMovementType::ProductionConsumption => 'consumo_produccion_id',
            InventoryMovementType::Waste => 'merma_id',
            InventoryMovementType::PositiveCountAdjustment,
            InventoryMovementType::NegativeCountAdjustment => 'detalle_conteo_inventario_id',
            default => null,
        };

        foreach ($causes as $column => $id) {
            if (($column === $causeColumn && ($id === null || $id <= 0))
                || ($column !== $causeColumn && $id !== null)) {
                throw new InventoryDomainException('La causal no corresponde al tipo de movimiento.');
            }
        }

        if (($data->type->isReversal()
                && ($data->reversedMovementId === null || $data->reversedMovementId <= 0))
            || (! $data->type->isReversal() && $data->reversedMovementId !== null)) {
            throw new InventoryDomainException('La referencia de reversion no corresponde al movimiento.');
        }

        $requiresReason = in_array($data->type, [
            InventoryMovementType::InitialBalance,
            InventoryMovementType::ManualInput,
            InventoryMovementType::ManualOutput,
        ], true);

        if (($requiresReason && $data->reason === null)
            || ($data->reason !== null && ($data->reason === '' || trim($data->reason) !== $data->reason))) {
            throw new InventoryDomainException('El motivo debe ser no vacio y no tener espacios exteriores.');
        }

        if ($data->type === InventoryMovementType::InitialBalance && ! Decimal::isZero($before)) {
            throw new InventoryDomainException('El saldo inicial debe partir de stock cero.');
        }

        $sku = $data->article->getAttribute('sku');
        $name = $data->article->getAttribute('nombre');
        $this->assertSnapshot($sku, 50);
        $this->assertSnapshot($name, 150);
        $this->assertSnapshot($data->unitCode, 50);
        $this->assertSnapshot($data->unitSymbol, 20);

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $sku) !== 1
            || preg_match('/^[a-z][a-z0-9_-]*$/D', $data->unitCode) !== 1) {
            throw new InventoryDomainException('Los codigos snapshot no tienen un formato valido.');
        }

        $createdAt = CarbonImmutable::now('UTC');

        if ($data->occurredAt->greaterThan($createdAt)) {
            throw new InventoryDomainException('La ocurrencia no puede ser posterior al registro.');
        }

        return [
            'articulo_id' => $articleId,
            'usuario_id' => $data->actorId,
            'usuario_autorizador_id' => $data->authorizerId,
            'tipo_movimiento' => $data->type->value,
            'cantidad_delta' => $delta,
            'stock_anterior' => $before,
            'stock_posterior' => $after,
            'clave_idempotencia' => strtolower($data->commandUuid),
            'secuencia_idempotencia' => $data->sequence,
            'ocurrido_en' => $data->occurredAt->setTimezone('UTC'),
            'motivo' => $data->reason,
            'articulo_sku_snapshot' => $sku,
            'articulo_nombre_snapshot' => $name,
            'unidad_codigo_snapshot' => $data->unitCode,
            'unidad_simbolo_snapshot' => $data->unitSymbol,
            ...$causes,
            'movimiento_revertido_id' => $data->reversedMovementId,
            'created_at' => $createdAt,
        ];
    }

    public function assertReversal(MovimientoInventario $original, InventoryMovementData $data): void
    {
        $this->assertTransaction($data);

        if ($original->getConnection() !== $data->article->getConnection()) {
            throw new LogicException('La reversion debe usar la misma conexion transaccional.');
        }

        $type = $original->getAttribute('tipo_movimiento');

        if (! $original->exists || ! $type instanceof InventoryMovementType
            || ! $type->isReversibleOriginal()
            || $type->reversalType() !== $data->type
            || $original->getAttribute('articulo_id') !== $data->article->getKey()
            || $data->reversedMovementId !== $original->getKey()
            || Decimal::compare(
                Decimal::subtract('0.000', $original->getAttribute('cantidad_delta'), 3),
                Decimal::quantity($data->delta),
                3,
            ) !== 0) {
            throw new InventoryDomainException('La reversion debe ser el inverso exacto del original.');
        }

        $causes = [
            'detalle_venta_id' => $data->saleDetailId,
            'detalle_recepcion_mercaderia_id' => $data->receiptDetailId,
            'detalle_produccion_id' => $data->productionDetailId,
            'consumo_produccion_id' => $data->productionConsumptionId,
            'merma_id' => $data->wasteId,
            'detalle_conteo_inventario_id' => $data->inventoryCountDetailId,
        ];

        foreach ($causes as $column => $id) {
            if ($original->getAttribute($column) !== $id) {
                throw new InventoryDomainException('La reversion debe conservar la causal original.');
            }
        }

        if ($original->newQuery()->where('movimiento_revertido_id', $original->getKey())->exists()) {
            throw new InventoryMovementAlreadyReversedException('El movimiento ya fue revertido.');
        }
    }

    private function assertTransaction(InventoryMovementData $data): void
    {
        if ($data->article->getConnection()->transactionLevel() <= 0) {
            throw new LogicException('El ledger requiere una transaccion externa activa.');
        }
    }

    private function assertSnapshot(mixed $value, int $maxLength): void
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value
            || mb_strlen($value) > $maxLength) {
            throw new InventoryDomainException('El snapshot no cumple el formato y largo fisicos.');
        }
    }

    private function persist(InventoryMovementData $data, array $attributes): MovimientoInventario
    {
        $movement = (new MovimientoInventario())->setConnection($data->article->getConnectionName());

        foreach ($attributes as $attribute => $value) {
            $movement->setAttribute($attribute, $value);
        }

        if (! $movement->save()) {
            throw new InventoryDomainException('No se pudo persistir el movimiento de inventario.');
        }

        return $movement;
    }
}
