<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Data\Inventory\InventoryStockMutation;
use App\Data\Inventory\InventoryStockSimulation;
use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\IdempotencyConflictException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Models\MovimientoInventario;
use App\Services\Inventory\InventoryLedgerWriter;
use App\Services\Inventory\InventoryLockManager;
use App\Services\Inventory\InventoryStockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class InventoryStockServiceTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $userId;

    private int $firstArticleId;

    private int $secondArticleId;

    private int $thirdArticleId;

    private int $fixtureUuidSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'stock_service'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $this->firstArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'stock_servicio_uno',
            'nombre' => 'Stock servicio uno',
            'stock_actual' => '10.000',
        ]);
        $this->secondArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'stock_servicio_dos',
            'nombre' => 'Stock servicio dos',
            'stock_actual' => '5.000',
        ]);
        $this->thirdArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'stock_servicio_tres',
            'nombre' => 'Stock servicio tres',
            'stock_actual' => '1.000',
        ]);
    }

    public function test_simulate_plan_is_pure_and_exposes_exact_per_article_totals(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $service = $this->service();

        $simulation = $service->simulatePlan($locked, $this->commandUuid(1), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-3.250', 2),
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualInput, '0.500', 1),
        ]);

        $this->assertInstanceOf(InventoryStockSimulation::class, $simulation);
        $this->assertSame([$this->firstArticleId], $simulation->articleIds());
        $this->assertSame('10.000', $simulation->stockBefore($this->firstArticleId));
        $this->assertSame('-2.750', $simulation->totalDelta($this->firstArticleId));
        $this->assertSame('7.250', $simulation->stockAfter($this->firstArticleId));
        $this->assertSame('10.000', $locked->get($this->firstArticleId)->stock_actual);
        $this->assertSame('10.000', $this->stockOf($this->firstArticleId));
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_simulation_rejects_a_plan_not_exactly_covered_by_the_locked_set(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        $this->expectException(InventoryDomainException::class);

        $this->service()->simulatePlan($locked, $this->commandUuid(2), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-1.000'),
            $this->mutation($this->secondArticleId, InventoryMovementType::ManualInput, '1.000'),
        ]);
    }

    public function test_simulation_rejects_negative_resulting_stock_before_any_write(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->thirdArticleId]);

        try {
            $this->service()->simulatePlan($locked, $this->commandUuid(3), [
                $this->mutation($this->thirdArticleId, InventoryMovementType::ManualOutput, '-1.001'),
            ]);
            $this->fail('The complete plan accepted negative resulting stock.');
        } catch (InsufficientStockException) {
            $this->assertSame('1.000', $this->stockOf($this->thirdArticleId));
            $this->assertSame(0, DB::table('movimientos_inventario')->count());
        }
    }

    public function test_multi_article_plan_is_rejected_all_or_nothing_when_one_article_is_insufficient(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([
            $this->thirdArticleId,
            $this->firstArticleId,
            $this->secondArticleId,
        ]);
        $before = $this->allStocks();

        try {
            $this->service()->simulatePlan($locked, $this->commandUuid(4), [
                $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-3.000'),
                $this->mutation($this->secondArticleId, InventoryMovementType::ManualInput, '2.000'),
                $this->mutation($this->thirdArticleId, InventoryMovementType::ManualOutput, '-2.000'),
            ]);
            $this->fail('The multi-article plan accepted one insufficient line.');
        } catch (InsufficientStockException) {
            $this->assertSame($before, $this->allStocks());
            $this->assertSame(0, DB::table('movimientos_inventario')->count());
        }
    }

    public function test_zero_delta_mutation_is_rejected_without_stock_ledger_or_sequence(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $before = $this->allStocks();

        try {
            $this->service()->simulatePlan($locked, $this->commandUuid(5), [
                $this->mutation($this->firstArticleId, InventoryMovementType::ManualInput, '0.000'),
            ]);
            $this->fail('The stock service accepted a zero delta mutation.');
        } catch (InvalidArgumentException) {
            $this->assertSame($before, $this->allStocks());
            $this->assertSame(0, DB::table('movimientos_inventario')->count());
        }
    }

    public function test_simulation_preserves_exact_thousandths_without_float_arithmetic(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(6), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualInput, '0.001', 1),
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-0.002', 2),
        ]);

        $this->assertSame('-0.001', $simulation->totalDelta($this->firstArticleId));
        $this->assertSame('9.999', $simulation->stockAfter($this->firstArticleId));
    }

    public function test_apply_plan_requires_the_callers_active_transaction(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $service = $this->service();
        $simulation = $service->simulatePlan($locked, $this->commandUuid(7), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-1.000'),
        ]);

        $this->runWithoutTransactions(function () use ($service, $simulation): void {
            $this->expectException(LogicException::class);

            $service->applyPlan($simulation);
        });
    }

    public function test_apply_plan_updates_stock_and_writes_one_matching_ledger_row_per_mutation(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([
            $this->secondArticleId,
            $this->firstArticleId,
        ]);
        $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(8), [
            $this->mutation($this->secondArticleId, InventoryMovementType::ManualInput, '2.000'),
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-3.250'),
        ]);

        $movements = $this->service()->applyPlan($simulation);
        $rows = DB::table('movimientos_inventario')->orderBy('secuencia_idempotencia')->get();

        $this->assertCount(2, $movements);
        $this->assertContainsOnlyInstancesOf(MovimientoInventario::class, $movements);
        $this->assertSame('6.750', $this->stockOf($this->firstArticleId));
        $this->assertSame('7.000', $this->stockOf($this->secondArticleId));
        $this->assertSame([$this->firstArticleId, $this->secondArticleId], $rows->pluck('articulo_id')->all());
        $this->assertSame([1, 2], $rows->pluck('secuencia_idempotencia')->all());
        $this->assertSame(['10.000', '5.000'], $rows->pluck('stock_anterior')->all());
        $this->assertSame(['-3.250', '2.000'], $rows->pluck('cantidad_delta')->all());
        $this->assertSame(['6.750', '7.000'], $rows->pluck('stock_posterior')->all());
        $this->assertSame(0, DB::table('stock_diario')->count());
    }

    public function test_positive_original_reversal_uses_changed_current_stock(): void
    {
        [$detailId, $originalId] = $this->createReceiptOriginal($this->firstArticleId);
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(16), [
            $this->reversalMutation(
                $this->firstArticleId,
                InventoryMovementType::ReceiptReversal,
                '-3.000',
                $originalId,
                receiptDetailId: $detailId,
            ),
        ]);

        $this->assertSame('10.000', $simulation->stockBefore($this->firstArticleId));
        $this->assertSame('7.000', $simulation->stockAfter($this->firstArticleId));

        $movements = $this->service()->applyPlan($simulation);
        $reversal = $movements[0];
        $original = MovimientoInventario::query()->findOrFail($originalId);

        $this->assertSame('7.000', $this->stockOf($this->firstArticleId));
        $this->assertSame('10.000', $reversal->stock_anterior);
        $this->assertSame('-3.000', $reversal->cantidad_delta);
        $this->assertSame('7.000', $reversal->stock_posterior);
        $this->assertSame($detailId, $reversal->detalle_recepcion_mercaderia_id);
        $this->assertSame($originalId, $reversal->movimiento_revertido_id);
        $this->assertSame('5.000', $original->stock_anterior);
        $this->assertSame('3.000', $original->cantidad_delta);
        $this->assertSame('8.000', $original->stock_posterior);
    }

    public function test_negative_original_reversal_uses_changed_current_stock(): void
    {
        DB::table('articulos')->where('id', $this->firstArticleId)->update(['stock_actual' => '5.000']);
        [$wasteId, $originalId] = $this->createWasteOriginal($this->firstArticleId);
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(17), [
            $this->reversalMutation(
                $this->firstArticleId,
                InventoryMovementType::WasteReversal,
                '3.000',
                $originalId,
                wasteId: $wasteId,
            ),
        ]);

        $movements = $this->service()->applyPlan($simulation);
        $reversal = $movements[0];

        $this->assertSame('5.000', $simulation->stockBefore($this->firstArticleId));
        $this->assertSame('8.000', $simulation->stockAfter($this->firstArticleId));
        $this->assertSame('8.000', $this->stockOf($this->firstArticleId));
        $this->assertSame('5.000', $reversal->stock_anterior);
        $this->assertSame('3.000', $reversal->cantidad_delta);
        $this->assertSame('8.000', $reversal->stock_posterior);
        $this->assertSame($wasteId, $reversal->merma_id);
        $this->assertSame($originalId, $reversal->movimiento_revertido_id);
    }

    public function test_positive_original_reversal_rejects_insufficient_current_stock_before_writing(): void
    {
        DB::table('articulos')->where('id', $this->firstArticleId)->update(['stock_actual' => '2.000']);
        [$detailId, $originalId] = $this->createReceiptOriginal($this->firstArticleId);
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        try {
            $this->service()->simulatePlan($locked, $this->commandUuid(18), [
                $this->reversalMutation(
                    $this->firstArticleId,
                    InventoryMovementType::ReceiptReversal,
                    '-3.000',
                    $originalId,
                    receiptDetailId: $detailId,
                ),
            ]);
            $this->fail('The reversal accepted insufficient current stock.');
        } catch (InsufficientStockException) {
            $this->assertSame('2.000', $this->stockOf($this->firstArticleId));
            $this->assertSame(0, DB::table('movimientos_inventario')
                ->where('tipo_movimiento', InventoryMovementType::ReceiptReversal->value)->count());
            $this->assertDatabaseHas('movimientos_inventario', ['id' => $originalId]);
        }
    }

    public function test_multi_article_reversal_simulation_is_atomic_when_one_current_stock_is_insufficient(): void
    {
        [$firstDetailId, $firstOriginalId] = $this->createReceiptOriginal($this->firstArticleId);
        [$thirdDetailId, $thirdOriginalId] = $this->createReceiptOriginal(
            $this->thirdArticleId,
            '0.000',
            '2.000',
            '2.000',
        );
        $locked = (new InventoryLockManager())->lockArticles([
            $this->firstArticleId,
            $this->thirdArticleId,
        ]);
        $before = $this->allStocks();

        try {
            $this->service()->simulatePlan($locked, $this->commandUuid(19), [
                $this->reversalMutation(
                    $this->firstArticleId,
                    InventoryMovementType::ReceiptReversal,
                    '-3.000',
                    $firstOriginalId,
                    receiptDetailId: $firstDetailId,
                ),
                $this->reversalMutation(
                    $this->thirdArticleId,
                    InventoryMovementType::ReceiptReversal,
                    '-2.000',
                    $thirdOriginalId,
                    receiptDetailId: $thirdDetailId,
                ),
            ]);
            $this->fail('The multi-article reversal accepted one insufficient article.');
        } catch (InsufficientStockException) {
            $this->assertSame($before, $this->allStocks());
            $this->assertSame(0, DB::table('movimientos_inventario')
                ->where('tipo_movimiento', InventoryMovementType::ReceiptReversal->value)->count());
        }
    }

    public function test_sequences_are_gapless_by_article_id_then_explicit_internal_position(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([
            $this->secondArticleId,
            $this->firstArticleId,
        ]);
        $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(9), [
            $this->mutation($this->secondArticleId, InventoryMovementType::ManualInput, '3.000', 1),
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-2.000', 2),
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualInput, '1.000', 1),
        ]);

        $this->service()->applyPlan($simulation);
        $rows = DB::table('movimientos_inventario')->orderBy('secuencia_idempotencia')->get();

        $this->assertSame([1, 2, 3], $rows->pluck('secuencia_idempotencia')->all());
        $this->assertSame(
            [$this->firstArticleId, $this->firstArticleId, $this->secondArticleId],
            $rows->pluck('articulo_id')->all(),
        );
        $this->assertSame(['1.000', '-2.000', '3.000'], $rows->pluck('cantidad_delta')->all());
        $this->assertSame(['10.000', '11.000', '5.000'], $rows->pluck('stock_anterior')->all());
        $this->assertSame(['11.000', '9.000', '8.000'], $rows->pluck('stock_posterior')->all());
    }

    public function test_duplicate_internal_positions_for_one_article_are_rejected_as_ambiguous(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        $this->expectException(InvalidArgumentException::class);

        $this->service()->simulatePlan($locked, $this->commandUuid(15), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualInput, '1.000', 1),
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-0.500', 1),
        ]);
    }

    public function test_compatible_retry_returns_existing_effects_without_duplicate_stock_or_ledger(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $service = $this->service();
        $simulation = $service->simulatePlan($locked, $this->commandUuid(10), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-1.500'),
        ]);

        $first = $service->applyPlan($simulation);
        $second = $service->applyPlan($simulation);

        $this->assertSame('8.500', $this->stockOf($this->firstArticleId));
        $this->assertSame(1, DB::table('movimientos_inventario')->count());
        $this->assertSame(
            array_map(static fn (MovimientoInventario $movement): int => (int) $movement->getKey(), $first),
            array_map(static fn (MovimientoInventario $movement): int => (int) $movement->getKey(), $second),
        );
    }

    public function test_same_command_uuid_with_incompatible_payload_is_rejected(): void
    {
        $commandUuid = $this->commandUuid(11);
        $service = $this->service();
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $first = $service->simulatePlan($locked, $commandUuid, [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-1.000'),
        ]);
        $service->applyPlan($first);
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        try {
            $second = $service->simulatePlan($locked, $commandUuid, [
                $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-2.000'),
            ]);
            $service->applyPlan($second);
            $this->fail('The same command UUID accepted an incompatible payload.');
        } catch (IdempotencyConflictException) {
            $this->assertSame('9.000', $this->stockOf($this->firstArticleId));
            $this->assertSame(1, DB::table('movimientos_inventario')->count());
        }
    }

    public function test_caller_rollback_removes_stock_and_ledger_together(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
            $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(12), [
                $this->mutation($this->firstArticleId, InventoryMovementType::ManualOutput, '-2.000'),
            ]);
            $this->service()->applyPlan($simulation);
            $this->assertSame('8.000', $this->stockOf($this->firstArticleId));
            $this->assertSame(1, DB::table('movimientos_inventario')->count());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame('10.000', $this->stockOf($this->firstArticleId));
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_initial_balance_is_excluded_from_normal_stock_application(): void
    {
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);

        $this->expectException(InventoryDomainException::class);

        $this->service()->simulatePlan($locked, $this->commandUuid(13), [
            $this->mutation(
                $this->firstArticleId,
                InventoryMovementType::InitialBalance,
                '10.000',
                reason: 'Apertura de inventario',
            ),
        ]);
    }

    public function test_apply_plan_leaves_the_callers_transaction_level_unchanged(): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $locked = (new InventoryLockManager())->lockArticles([$this->firstArticleId]);
        $simulation = $this->service()->simulatePlan($locked, $this->commandUuid(14), [
            $this->mutation($this->firstArticleId, InventoryMovementType::ManualInput, '1.000'),
        ]);

        $this->service()->applyPlan($simulation);

        $this->assertGreaterThan(0, $level);
        $this->assertSame($level, $connection->transactionLevel());
    }

    private function service(): InventoryStockService
    {
        return new InventoryStockService(new InventoryLedgerWriter());
    }

    private function mutation(
        int $articleId,
        InventoryMovementType $type,
        string $delta,
        int $position = 1,
        ?string $reason = 'Movimiento de inventario autorizado',
    ): InventoryStockMutation {
        return new InventoryStockMutation(
            articleId: $articleId,
            type: $type,
            delta: $delta,
            position: $position,
            occurredAt: CarbonImmutable::parse('2026-10-02T12:00:00+00:00'),
            actorId: $this->userId,
            authorizerId: null,
            reason: $reason,
            unitCode: 'kg',
            unitSymbol: 'kg',
            saleDetailId: null,
            receiptDetailId: null,
            productionDetailId: null,
            productionConsumptionId: null,
            wasteId: null,
            inventoryCountDetailId: null,
            reversedMovementId: null,
        );
    }

    private function reversalMutation(
        int $articleId,
        InventoryMovementType $type,
        string $delta,
        int $originalId,
        int $position = 1,
        ?int $receiptDetailId = null,
        ?int $wasteId = null,
    ): InventoryStockMutation {
        return new InventoryStockMutation(
            articleId: $articleId,
            type: $type,
            delta: $delta,
            position: $position,
            occurredAt: CarbonImmutable::parse('2026-10-02T13:00:00+00:00'),
            actorId: $this->userId,
            authorizerId: null,
            reason: 'Anulacion autorizada',
            unitCode: 'kg',
            unitSymbol: 'kg',
            saleDetailId: null,
            receiptDetailId: $receiptDetailId,
            productionDetailId: null,
            productionConsumptionId: null,
            wasteId: $wasteId,
            inventoryCountDetailId: null,
            reversedMovementId: $originalId,
        );
    }

    private function createReceiptOriginal(
        int $articleId,
        string $stockBefore = '5.000',
        string $delta = '3.000',
        string $stockAfter = '8.000',
    ): array {
        $receiptId = $this->createReceipt($this->userId, $this->nextFixtureUuid());
        $snapshot = $this->articleSnapshot($articleId);
        $detailId = $this->createReceiptDetail($receiptId, $articleId, [
            'articulo_sku_snapshot' => $snapshot->sku,
            'articulo_nombre_snapshot' => $snapshot->nombre,
            'unidad_codigo_snapshot' => $snapshot->unidad_codigo,
            'unidad_simbolo_snapshot' => $snapshot->unidad_simbolo,
            'cantidad' => $delta,
            'costo_unitario' => '1000.00',
        ]);
        $originalId = $this->createInventoryMovement(
            $articleId,
            $this->userId,
            InventoryMovementType::Receipt->value,
            $this->nextFixtureUuid(),
            [
                'cantidad_delta' => $delta,
                'stock_anterior' => $stockBefore,
                'stock_posterior' => $stockAfter,
                'articulo_sku_snapshot' => $snapshot->sku,
                'articulo_nombre_snapshot' => $snapshot->nombre,
                'unidad_codigo_snapshot' => $snapshot->unidad_codigo,
                'unidad_simbolo_snapshot' => $snapshot->unidad_simbolo,
                'detalle_recepcion_mercaderia_id' => $detailId,
            ],
        );

        return [$detailId, $originalId];
    }

    private function createWasteOriginal(int $articleId): array
    {
        $snapshot = $this->articleSnapshot($articleId);
        $wasteId = $this->createWaste($articleId, $this->userId, $this->nextFixtureUuid(), [
            'cantidad' => '3.000',
            'articulo_sku_snapshot' => $snapshot->sku,
            'articulo_nombre_snapshot' => $snapshot->nombre,
            'unidad_codigo_snapshot' => $snapshot->unidad_codigo,
            'unidad_simbolo_snapshot' => $snapshot->unidad_simbolo,
        ]);
        $originalId = $this->createInventoryMovement(
            $articleId,
            $this->userId,
            InventoryMovementType::Waste->value,
            $this->nextFixtureUuid(),
            [
                'cantidad_delta' => '-3.000',
                'stock_anterior' => '10.000',
                'stock_posterior' => '7.000',
                'articulo_sku_snapshot' => $snapshot->sku,
                'articulo_nombre_snapshot' => $snapshot->nombre,
                'unidad_codigo_snapshot' => $snapshot->unidad_codigo,
                'unidad_simbolo_snapshot' => $snapshot->unidad_simbolo,
                'merma_id' => $wasteId,
            ],
        );

        return [$wasteId, $originalId];
    }

    private function articleSnapshot(int $articleId): object
    {
        return DB::table('articulos')
            ->join('unidades_medida', 'unidades_medida.id', '=', 'articulos.unidad_id')
            ->where('articulos.id', $articleId)
            ->firstOrFail([
                'articulos.sku',
                'articulos.nombre',
                'unidades_medida.codigo as unidad_codigo',
                'unidades_medida.simbolo as unidad_simbolo',
            ]);
    }

    private function stockOf(int $articleId): string
    {
        return (string) DB::table('articulos')->where('id', $articleId)->value('stock_actual');
    }

    private function allStocks(): array
    {
        return DB::table('articulos')->orderBy('id')->pluck('stock_actual', 'id')->all();
    }

    private function commandUuid(int $suffix): string
    {
        return sprintf('93000000-0000-4000-8000-%012d', $suffix);
    }

    private function nextFixtureUuid(): string
    {
        return sprintf('93100000-0000-4000-8000-%012d', $this->fixtureUuidSequence++);
    }

    private function runWithoutTransactions(callable $callback): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();

        for ($remaining = $level; $remaining > 0; $remaining--) {
            $connection->rollBack();
        }

        try {
            $callback();
        } finally {
            for ($remaining = $level; $remaining > 0; $remaining--) {
                $connection->beginTransaction();
            }
        }
    }
}
