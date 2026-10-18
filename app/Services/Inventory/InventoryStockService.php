<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryMovementData;
use App\Data\Inventory\InventoryStockMutation;
use App\Data\Inventory\InventoryStockSimulation;
use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

final class InventoryStockService
{
    public function __construct(private readonly InventoryLedgerWriter $ledgerWriter)
    {
    }

    public function simulatePlan(
        LockedArticleSet $articles,
        string $commandUuid,
        iterable $mutations,
    ): InventoryStockSimulation {
        if (! Str::isUuid($commandUuid)) {
            throw new InvalidArgumentException('El comando debe tener un UUID valido.');
        }

        $mutations = $this->orderedMutations($mutations);
        $articles->assertExactlyCovers(array_column($mutations, 'articleId'));
        $before = [];
        $deltas = [];
        $after = [];

        foreach ($articles as $id => $article) {
            $stock = $article->getAttribute('stock_actual');

            if (! is_string($stock)) {
                throw new InvalidArgumentException('El stock debe ser una cantidad decimal string.');
            }

            $before[$id] = Decimal::quantity($stock);

            if (Decimal::sign($before[$id]) < 0) {
                throw new InventoryDomainException('El stock inicial no puede ser negativo.');
            }

            $deltas[$id] = '0.000';
            $after[$id] = $before[$id];
        }

        foreach ($mutations as $mutation) {
            $id = $mutation->articleId;
            $delta = Decimal::quantity($mutation->delta);
            $next = Decimal::add($after[$id], $delta, 3);

            if (Decimal::sign($next) < 0) {
                throw new InsufficientStockException('El plan deja stock insuficiente en un articulo.');
            }

            $after[$id] = Decimal::quantity($next);
            $deltas[$id] = Decimal::quantity(Decimal::add($deltas[$id], $delta, 3));
        }

        return new InventoryStockSimulation(
            $articles,
            strtolower($commandUuid),
            $mutations,
            $before,
            $deltas,
            $after,
        );
    }

    /** @return array<MovimientoInventario> */
    public function applyPlan(InventoryStockSimulation $simulation): array
    {
        $ids = $simulation->articleIds();
        $connection = ($ids === [] ? new Articulo() : $simulation->articles->get($ids[0]))->getConnection();

        if ($connection->transactionLevel() <= 0) {
            throw new LogicException('Aplicar stock requiere una transaccion externa activa.');
        }

        foreach ($simulation->articles as $id => $article) {
            if ($article->getConnection() !== $connection) {
                throw new LogicException('El plan debe usar una sola conexion transaccional.');
            }

            if (! $article->exists || ($article->getAttributes()[$article->getKeyName()] ?? null) !== $id) {
                throw new InventoryDomainException('La membresia bloqueada debe conservar los articulos persistidos.');
            }
        }

        $mutations = $this->orderedMutations($simulation->mutations);
        $simulation->articles->assertExactlyCovers(array_column($mutations, 'articleId'));
        $data = $this->movementData($simulation, $mutations);
        $expected = array_map($this->ledgerWriter->validatedAttributes(...), $data);
        $ledger = (new MovimientoInventario())->setConnection($connection->getName());
        $existing = $ledger->newQuery()
            ->where('clave_idempotencia', strtolower($simulation->commandUuid))
            ->orderBy('secuencia_idempotencia', 'asc')
            ->get()
            ->all();

        if ($existing !== []) {
            $this->assertCompatibleRetry($existing, $expected);

            return $existing;
        }

        $current = $this->simulatePlan($simulation->articles, $simulation->commandUuid, $mutations);

        foreach ($ids as $id) {
            if ($current->stockBefore($id) !== $simulation->stockBefore($id)
                || $current->totalDelta($id) !== $simulation->totalDelta($id)
                || $current->stockAfter($id) !== $simulation->stockAfter($id)) {
                throw new InventoryDomainException('El stock bloqueado ya no coincide con la simulacion.');
            }
        }

        $originals = [];
        $reversedIds = [];

        foreach ($data as $index => $movementData) {
            if (! $movementData->type->isReversal()) {
                continue;
            }

            $originalId = $movementData->reversedMovementId;

            if (isset($reversedIds[$originalId])) {
                throw new InventoryDomainException('El plan intenta revertir dos veces el mismo original.');
            }

            $original = $ledger->newQuery()->findOrFail($originalId);
            $this->ledgerWriter->assertReversal($original, $movementData);
            $originals[$index] = $original;
            $reversedIds[$originalId] = true;
        }

        $movements = [];

        foreach ($data as $index => $movementData) {
            $article = $movementData->article;
            $updated = $connection->table($article->getTable())
                ->where('id', $article->getKey())
                ->where('stock_actual', $movementData->stockBefore)
                ->update(['stock_actual' => $movementData->stockAfter]);

            if ($updated !== 1) {
                throw new InventoryDomainException('No se pudo aplicar la cadena de stock bloqueada.');
            }

            $movements[] = isset($originals[$index])
                ? $this->ledgerWriter->reverseLocked($originals[$index], $movementData)
                : $this->ledgerWriter->append($movementData);
            $article->setAttribute('stock_actual', $movementData->stockAfter);
            $article->syncOriginalAttribute('stock_actual');
        }

        return $movements;
    }

    /** @return array<InventoryStockMutation> */
    private function orderedMutations(iterable $mutations): array
    {
        $ordered = [];
        $positions = [];

        foreach ($mutations as $mutation) {
            if (! $mutation instanceof InventoryStockMutation
                || $mutation->articleId <= 0 || $mutation->position <= 0) {
                throw new InvalidArgumentException('La mutacion requiere articulo y posicion positivos.');
            }

            if ($mutation->type === InventoryMovementType::InitialBalance) {
                throw new InventoryDomainException('El saldo inicial no es una mutacion ordinaria de stock.');
            }

            $delta = Decimal::quantity($mutation->delta);

            if (Decimal::isZero($delta)) {
                throw new InvalidArgumentException('Una mutacion no puede tener delta cero.');
            }

            if (($mutation->type->isPositive() && Decimal::sign($delta) !== 1)
                || ($mutation->type->isNegative() && Decimal::sign($delta) !== -1)) {
                throw new InventoryDomainException('El signo del delta no corresponde al movimiento.');
            }

            if (isset($positions[$mutation->articleId][$mutation->position])) {
                throw new InvalidArgumentException('La posicion debe ser unica dentro de cada articulo.');
            }

            $positions[$mutation->articleId][$mutation->position] = true;
            $ordered[] = $mutation;
        }

        usort($ordered, static fn (InventoryStockMutation $left, InventoryStockMutation $right): int =>
            ($left->articleId <=> $right->articleId) ?: ($left->position <=> $right->position));

        return $ordered;
    }

    /** @return array<InventoryMovementData> */
    private function movementData(InventoryStockSimulation $simulation, array $mutations): array
    {
        $stocks = [];
        $totals = [];
        $data = [];

        foreach ($simulation->articleIds() as $id) {
            $stocks[$id] = Decimal::quantity($simulation->stockBefore($id));
            $totals[$id] = '0.000';
        }

        foreach ($mutations as $index => $mutation) {
            $id = $mutation->articleId;
            $delta = Decimal::quantity($mutation->delta);
            $after = Decimal::quantity(Decimal::add($stocks[$id], $delta, 3));

            if (Decimal::sign($after) < 0) {
                throw new InsufficientStockException('La cadena del plan no puede dejar stock negativo.');
            }

            $data[] = new InventoryMovementData(
                article: $simulation->articles->get($id),
                type: $mutation->type,
                delta: $delta,
                stockBefore: $stocks[$id],
                stockAfter: $after,
                commandUuid: $simulation->commandUuid,
                sequence: $index + 1,
                occurredAt: $mutation->occurredAt,
                actorId: $mutation->actorId,
                authorizerId: $mutation->authorizerId,
                reason: $mutation->reason,
                unitCode: $mutation->unitCode,
                unitSymbol: $mutation->unitSymbol,
                saleDetailId: $mutation->saleDetailId,
                receiptDetailId: $mutation->receiptDetailId,
                productionDetailId: $mutation->productionDetailId,
                productionConsumptionId: $mutation->productionConsumptionId,
                wasteId: $mutation->wasteId,
                inventoryCountDetailId: $mutation->inventoryCountDetailId,
                reversedMovementId: $mutation->reversedMovementId,
            );
            $stocks[$id] = $after;
            $totals[$id] = Decimal::quantity(Decimal::add($totals[$id], $delta, 3));
        }

        foreach ($simulation->articleIds() as $id) {
            if ($stocks[$id] !== $simulation->stockAfter($id) || $totals[$id] !== $simulation->totalDelta($id)) {
                throw new InventoryDomainException('Los totales no corresponden a la cadena simulada.');
            }
        }

        return $data;
    }

    private function assertCompatibleRetry(array $existing, array $expected): void
    {
        if (count($existing) !== count($expected)) {
            throw new IdempotencyConflictException('El UUID ya tiene un numero distinto de movimientos.');
        }

        foreach ($expected as $index => $attributes) {
            // Stock snapshots are derived effects, not inputs of a retried command.
            unset($attributes['created_at'], $attributes['stock_anterior'], $attributes['stock_posterior']);

            foreach ($attributes as $attribute => $value) {
                $actual = $existing[$index]->getAttribute($attribute);

                if ($actual instanceof InventoryMovementType) {
                    $actual = $actual->value;
                }

                $matches = $value instanceof CarbonImmutable
                    ? $actual instanceof CarbonImmutable && $actual->equalTo($value)
                    : $actual === $value;

                if (! $matches) {
                    throw new IdempotencyConflictException('El UUID ya fue aplicado con un payload distinto.');
                }
            }
        }
    }
}
