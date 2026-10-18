<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Inventory;

use App\Data\Inventory\InventoryMovementData;
use App\Enums\InventoryMovementType;
use App\Exceptions\Inventory\InventoryDomainException;
use App\Exceptions\Inventory\InventoryMovementAlreadyReversedException;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Services\Inventory\InventoryLedgerWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

final class InventoryLedgerWriterTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private Articulo $article;

    private Articulo $otherArticle;

    private int $userId;

    private int $wasteId;

    private int $otherWasteId;

    private int $countDetailId;

    private int $receiptDetailId;

    private int $uuidSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'ledger_writer'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $articleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'ledger_articulo',
            'nombre' => 'Articulo ledger',
            'stock_actual' => '10.000',
        ]);
        $otherArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'ledger_otro',
            'nombre' => 'Articulo ledger alternativo',
            'stock_actual' => '4.000',
        ]);
        $this->article = Articulo::query()->findOrFail($articleId);
        $this->otherArticle = Articulo::query()->findOrFail($otherArticleId);
        $receiptId = $this->createReceipt(
            $this->userId,
            '91000000-0000-4000-8000-000000000003',
        );
        $this->receiptDetailId = $this->createReceiptDetail($receiptId, $articleId, [
            'articulo_sku_snapshot' => 'ledger_articulo',
            'articulo_nombre_snapshot' => 'Articulo ledger',
            'cantidad' => '3.000',
            'costo_unitario' => '1000.00',
        ]);
        $this->wasteId = $this->createWaste(
            $articleId,
            $this->userId,
            '91000000-0000-4000-8000-000000000001',
            [
                'cantidad' => '1.250',
                'ocurrido_en' => '2026-10-01T12:00:00+00:00',
                'created_at' => '2026-10-01T12:00:00+00:00',
                'articulo_sku_snapshot' => 'ledger_articulo',
                'articulo_nombre_snapshot' => 'Articulo ledger',
            ],
        );
        $this->otherWasteId = $this->createWaste(
            $otherArticleId,
            $this->userId,
            '91000000-0000-4000-8000-000000000002',
            [
                'cantidad' => '1.250',
                'ocurrido_en' => '2026-10-01T12:00:00+00:00',
                'created_at' => '2026-10-01T12:00:00+00:00',
                'articulo_sku_snapshot' => 'ledger_otro',
                'articulo_nombre_snapshot' => 'Articulo ledger alternativo',
            ],
        );
        $countId = $this->createInventoryCount($this->userId, $this->userId);
        $this->countDetailId = $this->createInventoryCountDetail($countId, $articleId, [
            'articulo_sku_snapshot' => 'ledger_articulo',
            'articulo_nombre_snapshot' => 'Articulo ledger',
        ]);
    }

    public function test_append_requires_an_existing_transaction(): void
    {
        $data = $this->wasteMovementData();

        $this->runWithoutTransactions(function () use ($data): void {
            $this->expectException(LogicException::class);

            (new InventoryLedgerWriter())->append($data);
        });
    }

    public function test_reverse_locked_requires_an_existing_transaction(): void
    {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->wasteMovementData());
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();
        $data = $this->wasteReversalData((int) $original->getKey());

        $this->runWithoutTransactions(function () use ($writer, $original, $data): void {
            $this->expectException(LogicException::class);

            $writer->reverseLocked($original, $data);
        });
    }

    public function test_append_persists_exact_decimal_command_actor_and_causal_values(): void
    {
        $data = $this->wasteMovementData([
            'authorizerId' => $this->userId,
            'reason' => 'Merma autorizada',
        ]);

        $movement = (new InventoryLedgerWriter())->append($data);
        $row = DB::table('movimientos_inventario')->where('id', $movement->getKey())->first();

        $this->assertInstanceOf(MovimientoInventario::class, $movement);
        $this->assertNotNull($row);
        $this->assertSame((int) $this->article->getKey(), $row->articulo_id);
        $this->assertSame($this->userId, $row->usuario_id);
        $this->assertSame($this->userId, $row->usuario_autorizador_id);
        $this->assertSame('merma', $row->tipo_movimiento);
        $this->assertSame('-1.250', $row->cantidad_delta);
        $this->assertSame('10.000', $row->stock_anterior);
        $this->assertSame('8.750', $row->stock_posterior);
        $this->assertSame($data->commandUuid, $row->clave_idempotencia);
        $this->assertSame(1, $row->secuencia_idempotencia);
        $this->assertSame('Merma autorizada', $row->motivo);
        $this->assertSame($this->wasteId, $row->merma_id);
        $this->assertNull($row->movimiento_revertido_id);
    }

    #[DataProvider('invalidMovementValues')]
    public function test_append_rejects_invalid_sign_scale_stock_or_equation(
        array $overrides,
        string $exception,
    ): void {
        $this->expectException($exception);

        (new InventoryLedgerWriter())->append($this->wasteMovementData($overrides));
    }

    public static function invalidMovementValues(): array
    {
        return [
            'wrong sign' => [[
                'delta' => '1.250',
                'stockBefore' => '10.000',
                'stockAfter' => '11.250',
            ], InventoryDomainException::class],
            'zero delta' => [[
                'delta' => '0.000',
                'stockAfter' => '10.000',
            ], InventoryDomainException::class],
            'inconsistent equation' => [[
                'delta' => '-1.250',
                'stockAfter' => '9.000',
            ], InventoryDomainException::class],
            'negative previous stock' => [[
                'delta' => '-1.250',
                'stockBefore' => '-1.000',
                'stockAfter' => '-2.250',
            ], InventoryDomainException::class],
            'negative resulting stock' => [[
                'delta' => '-1.250',
                'stockBefore' => '1.000',
                'stockAfter' => '-0.250',
            ], InventoryDomainException::class],
            'excess scale' => [[
                'delta' => '-1.2501',
                'stockAfter' => '8.7499',
            ], InvalidArgumentException::class],
        ];
    }

    public function test_append_is_ledger_only_and_never_changes_current_or_daily_stock(): void
    {
        $stockBefore = DB::table('articulos')->where('id', $this->article->getKey())->value('stock_actual');

        (new InventoryLedgerWriter())->append($this->wasteMovementData());

        $this->assertSame(
            $stockBefore,
            DB::table('articulos')->where('id', $this->article->getKey())->value('stock_actual'),
        );
        $this->assertSame(1, DB::table('movimientos_inventario')->count());
        $this->assertSame(0, DB::table('stock_diario')->count());
    }

    public function test_reverse_locked_persists_the_exact_inverse_with_the_same_cause(): void
    {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->wasteMovementData());
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

        $reversal = $writer->reverseLocked($original, $this->wasteReversalData((int) $original->getKey()));
        $row = DB::table('movimientos_inventario')->where('id', $reversal->getKey())->first();

        $this->assertNotNull($row);
        $this->assertSame('anulacion_merma', $row->tipo_movimiento);
        $this->assertSame('1.250', $row->cantidad_delta);
        $this->assertSame('8.750', $row->stock_anterior);
        $this->assertSame('10.000', $row->stock_posterior);
        $this->assertSame($this->wasteId, $row->merma_id);
        $this->assertSame((int) $original->getKey(), $row->movimiento_revertido_id);
        $this->assertSame('10.000', DB::table('articulos')->where('id', $this->article->getKey())->value('stock_actual'));
    }

    public function test_positive_original_can_be_reversed_from_changed_current_stock(): void
    {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->receiptMovementData());
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

        $reversal = $writer->reverseLocked(
            $original,
            $this->receiptReversalData((int) $original->getKey()),
        );
        $originalRow = DB::table('movimientos_inventario')->where('id', $original->getKey())->first();

        $this->assertSame('5.000', $originalRow->stock_anterior);
        $this->assertSame('3.000', $originalRow->cantidad_delta);
        $this->assertSame('8.000', $originalRow->stock_posterior);
        $this->assertSame($this->receiptDetailId, $originalRow->detalle_recepcion_mercaderia_id);
        $this->assertSame('10.000', $reversal->stock_anterior);
        $this->assertSame('-3.000', $reversal->cantidad_delta);
        $this->assertSame('7.000', $reversal->stock_posterior);
        $this->assertSame($this->receiptDetailId, $reversal->detalle_recepcion_mercaderia_id);
        $this->assertSame((int) $original->getKey(), $reversal->movimiento_revertido_id);
        $this->assertNotSame($original->stock_posterior, $reversal->stock_anterior);
        $this->assertNotSame($original->stock_anterior, $reversal->stock_posterior);
    }

    public function test_negative_original_can_be_reversed_from_changed_current_stock(): void
    {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->wasteMovementData([
            'delta' => '-3.000',
            'stockBefore' => '10.000',
            'stockAfter' => '7.000',
        ]));
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

        $reversal = $writer->reverseLocked($original, $this->wasteReversalData(
            (int) $original->getKey(),
            ['delta' => '3.000', 'stockBefore' => '5.000', 'stockAfter' => '8.000'],
        ));

        $this->assertSame('5.000', $reversal->stock_anterior);
        $this->assertSame('3.000', $reversal->cantidad_delta);
        $this->assertSame('8.000', $reversal->stock_posterior);
        $this->assertSame($this->wasteId, $reversal->merma_id);
        $this->assertSame((int) $original->getKey(), $reversal->movimiento_revertido_id);
    }

    #[DataProvider('invalidReversalContracts')]
    public function test_reverse_locked_rejects_article_type_delta_equation_cause_or_pointer_mismatches(
        string $case,
    ): void {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->wasteMovementData());
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();
        $overrides = match ($case) {
            'article' => ['article' => $this->otherArticle],
            'type' => ['type' => InventoryMovementType::ReceiptReversal],
            'delta' => ['delta' => '0.500', 'stockAfter' => '9.250'],
            'equation' => ['stockBefore' => '8.000', 'stockAfter' => '10.000'],
            'cause' => ['wasteId' => $this->otherWasteId],
            'pointer' => ['reversedMovementId' => (int) $original->getKey() + 1000],
        };

        $this->expectException(InventoryDomainException::class);

        $writer->reverseLocked(
            $original,
            $this->wasteReversalData((int) $original->getKey(), $overrides),
        );
    }

    public static function invalidReversalContracts(): array
    {
        return [
            'different article' => ['article'],
            'wrong reversal type' => ['type'],
            'non-inverse delta' => ['delta'],
            'invalid reversal stock equation' => ['equation'],
            'different causal row' => ['cause'],
            'different original pointer' => ['pointer'],
        ];
    }

    public function test_second_reversal_of_the_same_locked_original_is_rejected(): void
    {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->wasteMovementData());
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();
        $writer->reverseLocked($original, $this->wasteReversalData((int) $original->getKey()));

        $this->expectException(InventoryMovementAlreadyReversedException::class);

        $writer->reverseLocked(
            $original,
            $this->wasteReversalData((int) $original->getKey(), [
                'commandUuid' => $this->nextUuid(),
            ]),
        );
    }

    #[DataProvider('nonReversibleTypes')]
    public function test_types_without_an_approved_reversal_cannot_be_reversed(string $type): void
    {
        $writer = new InventoryLedgerWriter();
        $original = $writer->append($this->nonReversibleMovementData($type));
        $original = MovimientoInventario::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

        $this->expectException(InventoryDomainException::class);

        $writer->reverseLocked(
            $original,
            $this->wasteReversalData((int) $original->getKey()),
        );
    }

    public static function nonReversibleTypes(): array
    {
        return [
            'initial balance' => ['saldo_inicial'],
            'positive count adjustment' => ['ajuste_conteo_positivo'],
            'negative count adjustment' => ['ajuste_conteo_negativo'],
            'manual input' => ['ajuste_manual_entrada'],
            'manual output' => ['ajuste_manual_salida'],
        ];
    }

    public function test_append_does_not_commit_the_callers_transaction(): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            (new InventoryLedgerWriter())->append($this->wasteMovementData());
            $this->assertSame(1, DB::table('movimientos_inventario')->count());
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, DB::table('movimientos_inventario')->count());
        $this->assertSame('10.000', DB::table('articulos')->where('id', $this->article->getKey())->value('stock_actual'));
    }

    public function test_append_keeps_the_callers_transaction_level_unchanged(): void
    {
        $connection = DB::connection();
        $level = $connection->transactionLevel();

        (new InventoryLedgerWriter())->append($this->wasteMovementData());

        $this->assertGreaterThan(0, $level);
        $this->assertSame($level, $connection->transactionLevel());
    }

    private function wasteMovementData(array $overrides = []): InventoryMovementData
    {
        return $this->movementData(array_merge([
            'article' => $this->article,
            'type' => InventoryMovementType::Waste,
            'delta' => '-1.250',
            'stockBefore' => '10.000',
            'stockAfter' => '8.750',
            'commandUuid' => $this->nextUuid(),
            'sequence' => 1,
            'occurredAt' => CarbonImmutable::parse('2026-10-01T12:00:00+00:00'),
            'actorId' => $this->userId,
            'reason' => null,
            'wasteId' => $this->wasteId,
        ], $overrides));
    }

    private function receiptMovementData(array $overrides = []): InventoryMovementData
    {
        return $this->movementData(array_merge([
            'article' => $this->article,
            'type' => InventoryMovementType::Receipt,
            'delta' => '3.000',
            'stockBefore' => '5.000',
            'stockAfter' => '8.000',
            'commandUuid' => $this->nextUuid(),
            'sequence' => 1,
            'occurredAt' => CarbonImmutable::parse('2026-10-01T12:00:00+00:00'),
            'actorId' => $this->userId,
            'reason' => null,
            'receiptDetailId' => $this->receiptDetailId,
        ], $overrides));
    }

    private function receiptReversalData(int $originalId, array $overrides = []): InventoryMovementData
    {
        return $this->movementData(array_merge([
            'article' => $this->article,
            'type' => InventoryMovementType::ReceiptReversal,
            'delta' => '-3.000',
            'stockBefore' => '10.000',
            'stockAfter' => '7.000',
            'commandUuid' => $this->nextUuid(),
            'sequence' => 1,
            'occurredAt' => CarbonImmutable::parse('2026-10-01T12:05:00+00:00'),
            'actorId' => $this->userId,
            'reason' => 'Anulacion autorizada',
            'receiptDetailId' => $this->receiptDetailId,
            'reversedMovementId' => $originalId,
        ], $overrides));
    }

    private function wasteReversalData(int $originalId, array $overrides = []): InventoryMovementData
    {
        return $this->movementData(array_merge([
            'article' => $this->article,
            'type' => InventoryMovementType::WasteReversal,
            'delta' => '1.250',
            'stockBefore' => '8.750',
            'stockAfter' => '10.000',
            'commandUuid' => $this->nextUuid(),
            'sequence' => 1,
            'occurredAt' => CarbonImmutable::parse('2026-10-01T12:05:00+00:00'),
            'actorId' => $this->userId,
            'reason' => 'Anulacion autorizada',
            'wasteId' => $this->wasteId,
            'reversedMovementId' => $originalId,
        ], $overrides));
    }

    private function nonReversibleMovementData(string $type): InventoryMovementData
    {
        $overrides = match ($type) {
            'saldo_inicial' => [
                'type' => InventoryMovementType::InitialBalance,
                'delta' => '10.000',
                'stockBefore' => '0.000',
                'stockAfter' => '10.000',
                'reason' => 'Apertura de inventario',
            ],
            'ajuste_conteo_positivo' => [
                'type' => InventoryMovementType::PositiveCountAdjustment,
                'delta' => '1.000',
                'stockBefore' => '10.000',
                'stockAfter' => '11.000',
                'inventoryCountDetailId' => $this->countDetailId,
            ],
            'ajuste_conteo_negativo' => [
                'type' => InventoryMovementType::NegativeCountAdjustment,
                'delta' => '-1.000',
                'stockBefore' => '10.000',
                'stockAfter' => '9.000',
                'inventoryCountDetailId' => $this->countDetailId,
            ],
            'ajuste_manual_entrada' => [
                'type' => InventoryMovementType::ManualInput,
                'delta' => '1.000',
                'stockBefore' => '10.000',
                'stockAfter' => '11.000',
                'reason' => 'Entrada autorizada',
            ],
            'ajuste_manual_salida' => [
                'type' => InventoryMovementType::ManualOutput,
                'delta' => '-1.000',
                'stockBefore' => '10.000',
                'stockAfter' => '9.000',
                'reason' => 'Salida autorizada',
            ],
        };

        return $this->movementData(array_merge([
            'article' => $this->article,
            'commandUuid' => $this->nextUuid(),
            'sequence' => 1,
            'occurredAt' => CarbonImmutable::parse('2026-10-01T11:00:00+00:00'),
            'actorId' => $this->userId,
        ], $overrides));
    }

    private function movementData(array $values): InventoryMovementData
    {
        return new InventoryMovementData(...array_merge([
            'article' => $this->article,
            'type' => InventoryMovementType::ManualInput,
            'delta' => '1.000',
            'stockBefore' => '10.000',
            'stockAfter' => '11.000',
            'commandUuid' => $this->nextUuid(),
            'sequence' => 1,
            'occurredAt' => CarbonImmutable::parse('2026-10-01T10:00:00+00:00'),
            'actorId' => $this->userId,
            'authorizerId' => null,
            'reason' => 'Movimiento autorizado',
            'unitCode' => 'kg',
            'unitSymbol' => 'kg',
            'saleDetailId' => null,
            'receiptDetailId' => null,
            'productionDetailId' => null,
            'productionConsumptionId' => null,
            'wasteId' => null,
            'inventoryCountDetailId' => null,
            'reversedMovementId' => null,
        ], $values));
    }

    private function nextUuid(): string
    {
        return sprintf('92000000-0000-4000-8000-%012d', $this->uuidSequence++);
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
