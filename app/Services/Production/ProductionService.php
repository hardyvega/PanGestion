<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Data\Inventory\InventoryStockMutation;
use App\Data\Production\AnnulProductionCommand;
use App\Data\Production\ConfirmProductionCommand;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionCode;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\StockDailyRebuilder;
use App\Support\CommercialDate;
use App\Support\Decimal;
use App\Support\PostgresUniqueViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductionService
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissions,
        private readonly InventoryLockManager $lockManager,
        private readonly InventoryStockService $stockService,
        private readonly StockDailyRebuilder $rebuilder,
    ) {
    }

    public function confirm(User $actor, ConfirmProductionCommand $command): object
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionConfirm);
        $actorId = (int) $actor->getKey();

        try {
            return DB::transaction(function () use ($actorId, $command): object {
            $production = $this->lockProduction($command->productionId);
            $context = $this->loadProductionContext($production);
            $existing = $this->lockCommandMovements($command->commandUuid);

            if ($production->estado === 'confirmada') {
                $this->assertCompatibleConfirmationRetry(
                    $production,
                    $context['detail'],
                    $context['consumptions'],
                    $existing,
                    $actorId,
                );

                return $production;
            }

            if ($production->estado === 'anulada') {
                throw new InventoryDomainException('Una produccion anulada no puede confirmarse.');
            }

            if ($production->estado !== 'borrador') {
                throw new InventoryDomainException('La produccion no admite confirmacion en su estado actual.');
            }

            if ($existing->isNotEmpty()) {
                throw new IdempotencyConflictException('El UUID ya identifica otro comando de inventario.');
            }

            $this->assertProductionContext($context);
            $articleIds = $this->affectedArticleIds($context['detail'], $context['consumptions']);
            $articles = $this->lockManager->lockArticles($articleIds);
            $this->assertActiveProductionArticles($context, $articles);

            $confirmedAt = CarbonImmutable::now('UTC');
            $mutations = $this->confirmationMutations(
                $context,
                $articles,
                $actorId,
                $confirmedAt,
            );
            $simulation = $this->stockService->simulatePlan($articles, $command->commandUuid, $mutations);
            $this->stockService->applyPlan($simulation);
            $this->rebuilder->rebuild($articleIds, CommercialDate::fromInstant($confirmedAt));

            DB::table('producciones')
                ->where('id', $command->productionId)
                ->update([
                    'estado' => 'confirmada',
                    'usuario_confirmador_id' => $actorId,
                    'confirmada_en' => $confirmedAt->format('Y-m-d H:i:s.uP'),
                    'usuario_anulador_id' => null,
                    'anulada_en' => null,
                    'motivo_anulacion' => null,
                    'updated_at' => $confirmedAt->format('Y-m-d H:i:s.uP'),
                ]);

            return $this->lockProduction($command->productionId);
            });
        } catch (QueryException $exception) {
            if (! PostgresUniqueViolation::for($exception, 'mov_inv_idempotencia_secuencia_unique')) {
                throw $exception;
            }

            // The failed transaction is rolled back before the retry reads
            // the production and the winning causal movements.
            return $this->confirm($actor, $command);
        }
    }

    public function annul(User $actor, AnnulProductionCommand $command): object
    {
        $this->permissions->authorize($actor, PermissionCode::ProductionAnnul);
        $actorId = (int) $actor->getKey();

        try {
            return DB::transaction(function () use ($actorId, $command): object {
            $production = $this->lockProduction($command->productionId);
            $context = $this->loadProductionContext($production);
            $originals = $this->productionOriginals($context['detail'], $context['consumptions']);
            $existing = $this->lockCommandMovements($command->commandUuid);

            if ($production->estado === 'borrador') {
                throw new InventoryDomainException('Una produccion en borrador no puede anularse.');
            }

            $articleIds = $this->affectedArticleIds($context['detail'], $context['consumptions']);
            $articles = $this->lockManager->lockArticles($articleIds);
            $originals = $this->lockProductionOriginals($originals);
            $reversals = $this->lockReversals($originals);

            if ($production->estado === 'anulada') {
                $this->assertCompatibleAnnulmentRetry(
                    $production,
                    $context['detail'],
                    $context['consumptions'],
                    $originals,
                    $reversals,
                    $existing,
                    $actorId,
                    $command->reason,
                );

                return $production;
            }

            if ($production->estado !== 'confirmada') {
                throw new InventoryDomainException('La produccion no admite anulacion en su estado actual.');
            }

            if ($existing->isNotEmpty()) {
                throw new IdempotencyConflictException('El UUID ya identifica otro comando de inventario.');
            }

            $this->assertProductionOriginals(
                $context['detail'],
                $context['consumptions'],
                $originals,
            );

            if ($reversals->isNotEmpty()) {
                throw new InventoryDomainException('La produccion contiene movimientos ya revertidos.');
            }

            $annulledAt = CarbonImmutable::now('UTC');
            $mutations = $this->annulmentMutations($originals, $articles, $actorId, $command->reason, $annulledAt);
            $simulation = $this->stockService->simulatePlan($articles, $command->commandUuid, $mutations);
            $this->stockService->applyPlan($simulation);
            $this->rebuilder->rebuild($articleIds, CommercialDate::fromInstant($annulledAt));

            DB::table('producciones')
                ->where('id', $command->productionId)
                ->update([
                    'estado' => 'anulada',
                    'usuario_anulador_id' => $actorId,
                    'anulada_en' => $annulledAt->format('Y-m-d H:i:s.uP'),
                    'motivo_anulacion' => $command->reason,
                    'updated_at' => $annulledAt->format('Y-m-d H:i:s.uP'),
                ]);

            return $this->lockProduction($command->productionId);
            });
        } catch (QueryException $exception) {
            if (! PostgresUniqueViolation::for($exception, 'mov_inv_idempotencia_secuencia_unique')) {
                throw $exception;
            }

            return $this->annul($actor, $command);
        }
    }

    /** @return array{recipe: object, recipeDetails: Collection, detail: object, consumptions: Collection} */
    private function loadProductionContext(object $production): array
    {
        $recipe = DB::table('recetas')
            ->where('id', (int) $production->receta_id)
            ->lockForUpdate()
            ->first();

        if ($recipe === null) {
            throw new InventoryDomainException('La produccion requiere una receta existente.');
        }

        $recipeDetails = DB::table('detalle_recetas')
            ->where('receta_id', (int) $recipe->id)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        $details = DB::table('detalle_producciones')
            ->where('produccion_id', (int) $production->id)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
        $consumptions = DB::table('consumos_produccion')
            ->where('produccion_id', (int) $production->id)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        if ($details->count() !== 1) {
            throw new InventoryDomainException('La produccion requiere exactamente un detalle elaborado.');
        }

        return [
            'recipe' => $recipe,
            'recipeDetails' => $recipeDetails,
            'detail' => $details->first(),
            'consumptions' => $consumptions,
        ];
    }

    private function assertProductionContext(array $context): void
    {
        $recipe = $context['recipe'];
        $detail = $context['detail'];
        $recipeDetails = $context['recipeDetails'];
        $consumptions = $context['consumptions'];

        if (! (bool) $recipe->activa) {
            throw new InventoryDomainException('La produccion requiere una receta activa.');
        }

        if ($detail->cantidad_real === null) {
            throw new InventoryDomainException('El detalle de produccion requiere cantidad real capturada.');
        }

        $this->assertNonNegativeQuantity((string) $detail->cantidad_real, 'La cantidad real producida no es valida.');

        $recipeDetailIds = [];
        foreach ($recipeDetails as $recipeDetail) {
            $recipeDetailIds[(int) $recipeDetail->id] = $recipeDetail;
        }

        $seenRecipeDetails = [];
        foreach ($consumptions as $consumption) {
            if ($consumption->cantidad_real === null) {
                throw new InventoryDomainException('Cada consumo de produccion requiere cantidad real capturada.');
            }

            $this->assertNonNegativeQuantity((string) $consumption->cantidad_real, 'La cantidad real consumida no es valida.');

            if ($consumption->detalle_receta_id !== null) {
                $recipeDetail = $recipeDetailIds[(int) $consumption->detalle_receta_id] ?? null;

                if ($recipeDetail === null
                    || (int) $recipeDetail->articulo_componente_id !== (int) $consumption->articulo_componente_id) {
                    throw new InventoryDomainException('El consumo planificado no coincide con la receta de la produccion.');
                }

                $seenRecipeDetails[(int) $consumption->detalle_receta_id] = true;
            } elseif ($consumption->cantidad_teorica !== null) {
                throw new InventoryDomainException('Un consumo extra no puede tener cantidad teorica.');
            }
        }

        foreach ($recipeDetailIds as $recipeDetailId => $_recipeDetail) {
            if (! isset($seenRecipeDetails[$recipeDetailId])) {
                throw new InventoryDomainException('La produccion no contiene todos los consumos de la receta.');
            }
        }
    }

    private function assertActiveProductionArticles(array $context, $articles): void
    {
        $detail = $context['detail'];
        $product = $articles->get((int) $detail->articulo_id);
        $recipe = $context['recipe'];

        if ((int) $recipe->articulo_id !== (int) $detail->articulo_id
            || $product->tipo_articulo !== 'elaborado'
            || ! $product->activo) {
            throw new InventoryDomainException('El producto elaborado debe coincidir con la receta y estar activo.');
        }

        foreach ($context['consumptions'] as $consumption) {
            $component = $articles->get((int) $consumption->articulo_componente_id);

            if ($component->tipo_articulo !== 'materia_prima' || ! $component->activo) {
                throw new InventoryDomainException('Todos los componentes de produccion deben ser materias primas activas.');
            }
        }
    }

    /** @return list<int> */
    private function affectedArticleIds(object $detail, Collection $consumptions): array
    {
        $ids = [(int) $detail->articulo_id => true];

        foreach ($consumptions as $consumption) {
            $ids[(int) $consumption->articulo_componente_id] = true;
        }

        ksort($ids, SORT_NUMERIC);

        return array_keys($ids);
    }

    /** @return list<InventoryStockMutation> */
    private function confirmationMutations(array $context, $articles, int $actorId, CarbonImmutable $occurredAt): array
    {
        $positions = [];
        $mutations = [];

        foreach ($context['consumptions'] as $consumption) {
            $quantity = Decimal::quantity((string) $consumption->cantidad_real);

            if (Decimal::isZero($quantity)) {
                continue;
            }

            $articleId = (int) $consumption->articulo_componente_id;
            $mutations[] = $this->mutation(
                $articles,
                $articleId,
                InventoryMovementType::ProductionConsumption,
                Decimal::subtract('0.000', $quantity, 3),
                $this->nextPosition($positions, $articleId),
                $occurredAt,
                $actorId,
                productionConsumptionId: (int) $consumption->id,
            );
        }

        $quantity = Decimal::quantity((string) $context['detail']->cantidad_real);

        if (! Decimal::isZero($quantity)) {
            $articleId = (int) $context['detail']->articulo_id;
            $mutations[] = $this->mutation(
                $articles,
                $articleId,
                InventoryMovementType::ProductionInput,
                $quantity,
                $this->nextPosition($positions, $articleId),
                $occurredAt,
                $actorId,
                productionDetailId: (int) $context['detail']->id,
            );
        }

        return $mutations;
    }

    /** @return list<InventoryStockMutation> */
    private function annulmentMutations(Collection $originals, $articles, int $actorId, string $reason, CarbonImmutable $occurredAt): array
    {
        $positions = [];
        $mutations = [];

        foreach ($originals->sortBy(fn (MovimientoInventario $movement): array => [$movement->articulo_id, $movement->id]) as $original) {
            $articleId = (int) $original->articulo_id;
            $mutations[] = $this->mutation(
                $articles,
                $articleId,
                $original->tipo_movimiento->reversalType(),
                Decimal::subtract('0.000', $original->cantidad_delta, 3),
                $this->nextPosition($positions, $articleId),
                $occurredAt,
                $actorId,
                reason: $reason,
                productionDetailId: $original->detalle_produccion_id,
                productionConsumptionId: $original->consumo_produccion_id,
                reversedMovementId: (int) $original->getKey(),
            );
        }

        return $mutations;
    }

    private function mutation(
        $articles,
        int $articleId,
        InventoryMovementType $type,
        string $delta,
        int $position,
        CarbonImmutable $occurredAt,
        int $actorId,
        ?string $reason = null,
        ?int $productionDetailId = null,
        ?int $productionConsumptionId = null,
        ?int $reversedMovementId = null,
    ): InventoryStockMutation {
        $article = $articles->get($articleId);
        $unit = DB::table('unidades_medida')->where('id', $article->unidad_id)->first(['codigo', 'simbolo']);

        if ($unit === null) {
            throw new InventoryDomainException('El articulo requiere una unidad de medida existente.');
        }

        return new InventoryStockMutation(
            articleId: $articleId,
            type: $type,
            delta: $delta,
            position: $position,
            occurredAt: $occurredAt,
            actorId: $actorId,
            authorizerId: null,
            reason: $reason,
            unitCode: $unit->codigo,
            unitSymbol: $unit->simbolo,
            saleDetailId: null,
            receiptDetailId: null,
            productionDetailId: $productionDetailId,
            productionConsumptionId: $productionConsumptionId,
            wasteId: null,
            inventoryCountDetailId: null,
            reversedMovementId: $reversedMovementId,
        );
    }

    /** @param array<int, int> $positions */
    private function nextPosition(array &$positions, int $articleId): int
    {
        $positions[$articleId] = ($positions[$articleId] ?? 0) + 1;

        return $positions[$articleId];
    }

    private function lockProduction(int $productionId): object
    {
        $production = DB::table('producciones')->where('id', $productionId)->lockForUpdate()->first();

        if ($production === null) {
            throw (new ModelNotFoundException())->setModel('producciones', [$productionId]);
        }

        return $production;
    }

    private function lockCommandMovements(string $commandUuid): Collection
    {
        return MovimientoInventario::query()
            ->where('clave_idempotencia', $commandUuid)
            ->orderBy('secuencia_idempotencia', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    private function productionOriginals(object $detail, Collection $consumptions): Collection
    {
        $detailId = (int) $detail->id;
        $consumptionIds = $consumptions->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return MovimientoInventario::query()
            ->where(static function ($query) use ($detailId, $consumptionIds): void {
                $query->where('detalle_produccion_id', $detailId);

                if ($consumptionIds !== []) {
                    $query->orWhereIn('consumo_produccion_id', $consumptionIds);
                }
            })
            ->whereIn('tipo_movimiento', [
                InventoryMovementType::ProductionInput->value,
                InventoryMovementType::ProductionConsumption->value,
            ])
            ->orderBy('id', 'asc')
            ->get();
    }

    private function lockProductionOriginals(Collection $originals): Collection
    {
        if ($originals->isEmpty()) {
            return $originals;
        }

        return MovimientoInventario::query()
            ->whereIn('id', $originals->pluck('id')->map(static fn ($id): int => (int) $id)->all())
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    private function lockReversals(Collection $originals): Collection
    {
        if ($originals->isEmpty()) {
            return collect();
        }

        return MovimientoInventario::query()
            ->whereIn('movimiento_revertido_id', $originals->pluck('id')->map(static fn ($id): int => (int) $id)->all())
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();
    }

    private function assertProductionOriginals(object $detail, Collection $consumptions, Collection $originals): void
    {
        $expected = $this->expectedOriginals($detail, $consumptions);

        if ($originals->count() !== count($expected)) {
            throw new InventoryDomainException('La produccion no tiene exactamente sus movimientos originales.');
        }

        foreach ($originals as $original) {
            $key = $this->movementCauseKey($original);

            if (! isset($expected[$key])
                || $original->tipo_movimiento !== $expected[$key]['type']
                || $original->articulo_id !== $expected[$key]['articleId']
                || Decimal::compare($original->cantidad_delta, $expected[$key]['delta'], 3) !== 0
                || $original->movimiento_revertido_id !== null) {
                throw new InventoryDomainException('Un movimiento original no coincide con la produccion.');
            }
        }
    }

    private function assertCompatibleConfirmationRetry(
        object $production,
        object $detail,
        Collection $consumptions,
        Collection $movements,
        int $actorId,
    ): void {
        if ((int) $production->usuario_confirmador_id !== $actorId) {
            throw new IdempotencyConflictException('El UUID no corresponde a esta confirmacion.');
        }

        $expected = $this->expectedOriginals($detail, $consumptions);

        if ($movements->count() !== count($expected)) {
            throw new IdempotencyConflictException('El UUID no identifica todos los movimientos de la confirmacion.');
        }

        foreach ($movements as $movement) {
            $key = $this->movementCauseKey($movement);

            if (! isset($expected[$key])
                || $movement->tipo_movimiento !== $expected[$key]['type']
                || $movement->articulo_id !== $expected[$key]['articleId']
                || $movement->usuario_id !== $actorId
                || $movement->usuario_autorizador_id !== null
                || $movement->movimiento_revertido_id !== null
                || Decimal::compare($movement->cantidad_delta, $expected[$key]['delta'], 3) !== 0) {
                throw new IdempotencyConflictException('El UUID ya fue aplicado con una confirmacion incompatible.');
            }
        }
    }

    private function assertCompatibleAnnulmentRetry(
        object $production,
        object $detail,
        Collection $consumptions,
        Collection $originals,
        Collection $reversals,
        Collection $movements,
        int $actorId,
        string $reason,
    ): void {
        $this->assertProductionOriginals($detail, $consumptions, $originals);

        if ((int) $production->usuario_anulador_id !== $actorId
            || $production->motivo_anulacion !== $reason
            || $reversals->count() !== $originals->count()
            || $movements->count() !== $originals->count()) {
            throw new IdempotencyConflictException('El UUID no corresponde a esta anulacion.');
        }

        $movementIds = $movements->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $reversalIds = $reversals->pluck('id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();

        if ($movementIds !== $reversalIds) {
            throw new IdempotencyConflictException('El UUID no identifica todas las reversiones.');
        }

        foreach ($reversals as $reversal) {
            $original = $originals->firstWhere('id', $reversal->movimiento_revertido_id);

            if ($original === null
                || $reversal->usuario_id !== $actorId
                || $reversal->motivo !== $reason
                || $reversal->tipo_movimiento !== $original->tipo_movimiento->reversalType()
                || Decimal::compare(
                    $reversal->cantidad_delta,
                    Decimal::subtract('0.000', $original->cantidad_delta, 3),
                    3,
                ) !== 0) {
                throw new IdempotencyConflictException('El UUID ya fue aplicado con una anulacion incompatible.');
            }
        }
    }

    /** @return array<string, array{type: InventoryMovementType, articleId: int, delta: string}> */
    private function expectedOriginals(object $detail, Collection $consumptions): array
    {
        $expected = [];
        $quantity = Decimal::quantity((string) $detail->cantidad_real);

        if (! Decimal::isZero($quantity)) {
            $expected['detail:'.(int) $detail->id] = [
                'type' => InventoryMovementType::ProductionInput,
                'articleId' => (int) $detail->articulo_id,
                'delta' => $quantity,
            ];
        }

        foreach ($consumptions as $consumption) {
            $quantity = Decimal::quantity((string) $consumption->cantidad_real);

            if (Decimal::isZero($quantity)) {
                continue;
            }

            $expected['consumption:'.(int) $consumption->id] = [
                'type' => InventoryMovementType::ProductionConsumption,
                'articleId' => (int) $consumption->articulo_componente_id,
                'delta' => Decimal::subtract('0.000', $quantity, 3),
            ];
        }

        return $expected;
    }

    private function movementCauseKey(MovimientoInventario $movement): string
    {
        return match ($movement->tipo_movimiento) {
            InventoryMovementType::ProductionInput,
            InventoryMovementType::ProductionInputReversal => 'detail:'.(int) $movement->detalle_produccion_id,
            InventoryMovementType::ProductionConsumption,
            InventoryMovementType::ProductionConsumptionReversal => 'consumption:'.(int) $movement->consumo_produccion_id,
            default => 'unknown:'.(int) $movement->getKey(),
        };
    }

    private function assertNonNegativeQuantity(string $value, string $message): void
    {
        try {
            if (Decimal::sign(Decimal::quantity($value)) < 0) {
                throw new InventoryDomainException($message);
            }
        } catch (\InvalidArgumentException) {
            throw new InventoryDomainException($message);
        }
    }
}
