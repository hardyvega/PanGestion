<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class InventoryMovementConstraintsTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockElevenRecords;
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const CREATED_AT = '2026-09-20 10:00:00-03';

    private const EARLIER_AT = '2026-09-20 09:00:00-03';

    private const FUTURE_AT = '2026-09-20 11:00:00-03';

    private array $origins;

    private int $userId;

    private int $uuidSequence = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'movimiento_inventario'],
        );
        $categoryId = $this->createArticleCategory();
        $unitId = $this->createUnitOfMeasure();
        $articleId = $this->createArticle($categoryId, $unitId);
        $componentArticleId = $this->createArticle($categoryId, $unitId, [
            'sku' => 'harina_prueba',
            'nombre' => 'Harina de prueba',
            'tipo_articulo' => 'materia_prima',
        ]);
        $this->origins = $this->createInventoryMovementOriginRecords(
            $this->userId,
            $articleId,
            $componentArticleId,
        );
    }

    #[DataProvider('movementTypes')]
    public function test_each_inventory_movement_type_accepts_valid_structure(string $type): void
    {
        $movementId = $this->createInventoryMovement(
            $this->inventoryMovementArticleId($type, $this->origins),
            $this->userId,
            $type,
            $this->nextUuid(),
            $this->validOverridesForType($type),
        );

        $this->assertSame(
            $type,
            DB::table('movimientos_inventario')->where('id', $movementId)->value('tipo_movimiento'),
        );
    }

    public static function movementTypes(): array
    {
        return [
            'opening balance' => ['saldo_inicial'],
            'sale' => ['venta'],
            'sale cancellation' => ['anulacion_venta'],
            'receipt' => ['recepcion'],
            'receipt cancellation' => ['anulacion_recepcion'],
            'production entry' => ['produccion_entrada'],
            'production entry cancellation' => ['anulacion_produccion_entrada'],
            'production consumption' => ['produccion_consumo'],
            'production consumption cancellation' => ['anulacion_produccion_consumo'],
            'waste' => ['merma'],
            'waste cancellation' => ['anulacion_merma'],
            'positive count adjustment' => ['ajuste_conteo_positivo'],
            'negative count adjustment' => ['ajuste_conteo_negativo'],
            'manual entry' => ['ajuste_manual_entrada'],
            'manual output' => ['ajuste_manual_salida'],
        ];
    }

    public function test_invalid_inventory_movement_type_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'traslado',
            $this->nextUuid(),
        );
    }

    #[DataProvider('invalidDeltas')]
    public function test_zero_or_nan_inventory_delta_is_rejected(
        string $delta,
        string $previousStock,
        string $resultingStock,
    ): void {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            [
                'cantidad_delta' => $delta,
                'stock_anterior' => $previousStock,
                'stock_posterior' => $resultingStock,
            ],
        );
    }

    public static function invalidDeltas(): array
    {
        return [
            'zero' => ['0.000', '1.000', '1.000'],
            'NaN' => ['NaN', '1.000', '1.000'],
        ];
    }

    #[DataProvider('movementTypes')]
    public function test_each_inventory_movement_type_rejects_wrong_delta_sign(string $type): void
    {
        $positiveType = in_array($type, [
            'saldo_inicial',
            'anulacion_venta',
            'recepcion',
            'produccion_entrada',
            'anulacion_produccion_consumo',
            'anulacion_merma',
            'ajuste_conteo_positivo',
            'ajuste_manual_entrada',
        ], true);
        $stockOverrides = $positiveType
            ? ['cantidad_delta' => '-1.000', 'stock_anterior' => '2.000', 'stock_posterior' => '1.000']
            : ['cantidad_delta' => '1.000', 'stock_anterior' => '0.000', 'stock_posterior' => '1.000'];

        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->inventoryMovementArticleId($type, $this->origins),
            $this->userId,
            $type,
            $this->nextUuid(),
            array_merge($this->validOverridesForType($type), $stockOverrides),
        );
    }

    #[DataProvider('invalidStockStructures')]
    public function test_invalid_inventory_stock_values_are_rejected(
        string $type,
        string $delta,
        string $previousStock,
        string $resultingStock,
    ): void {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            $type,
            $this->nextUuid(),
            [
                'cantidad_delta' => $delta,
                'stock_anterior' => $previousStock,
                'stock_posterior' => $resultingStock,
            ],
        );
    }

    public static function invalidStockStructures(): array
    {
        return [
            'negative previous stock' => ['ajuste_manual_entrada', '1.000', '-1.000', '0.000'],
            'negative resulting stock' => ['ajuste_manual_salida', '-1.000', '0.000', '-1.000'],
            'NaN previous stock' => ['ajuste_manual_entrada', '1.000', 'NaN', 'NaN'],
            'NaN resulting stock' => ['ajuste_manual_entrada', '1.000', '0.000', 'NaN'],
            'inconsistent equation' => ['ajuste_manual_entrada', '1.000', '0.000', '2.000'],
        ];
    }

    public function test_consistent_inventory_stock_equation_is_accepted(): void
    {
        $movementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_salida',
            $this->nextUuid(),
            [
                'cantidad_delta' => '-1.250',
                'stock_anterior' => '3.000',
                'stock_posterior' => '1.750',
            ],
        );

        $this->assertSame(
            '1.750',
            DB::table('movimientos_inventario')->where('id', $movementId)->value('stock_posterior'),
        );
    }

    public function test_idempotency_sequence_one_is_accepted(): void
    {
        $movementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            ['secuencia_idempotencia' => 1],
        );

        $this->assertSame(
            1,
            DB::table('movimientos_inventario')->where('id', $movementId)->value('secuencia_idempotencia'),
        );
    }

    #[DataProvider('invalidIdempotencySequences')]
    public function test_non_positive_idempotency_sequence_is_rejected(int $sequence): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            ['secuencia_idempotencia' => $sequence],
        );
    }

    public static function invalidIdempotencySequences(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_duplicate_idempotency_key_and_sequence_are_rejected(): void
    {
        $key = $this->nextUuid();
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $key,
        );

        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $key,
        );
    }

    public function test_same_idempotency_key_with_distinct_sequences_is_accepted(): void
    {
        $key = $this->nextUuid();
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $key,
        );
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $key,
            ['secuencia_idempotencia' => 2],
        );

        $this->assertSame(
            2,
            DB::table('movimientos_inventario')->where('clave_idempotencia', $key)->count(),
        );
    }

    #[DataProvider('invalidRequiredReasons')]
    public function test_required_inventory_movement_reason_is_rejected_when_invalid(
        string $type,
        ?string $reason,
    ): void {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            $type,
            $this->nextUuid(),
            ['motivo' => $reason],
        );
    }

    public static function invalidRequiredReasons(): array
    {
        return [
            'opening balance without reason' => ['saldo_inicial', null],
            'opening balance spaces' => ['saldo_inicial', '   '],
            'manual entry without reason' => ['ajuste_manual_entrada', null],
            'manual output without reason' => ['ajuste_manual_salida', null],
        ];
    }

    public function test_optional_reason_can_be_null(): void
    {
        $movementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides('venta', $this->origins),
        );

        $this->assertNull(DB::table('movimientos_inventario')->where('id', $movementId)->value('motivo'));
    }

    public function test_optional_reason_rejects_whitespace(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            array_merge(
                $this->inventoryMovementCauseOverrides('venta', $this->origins),
                ['motivo' => '   '],
            ),
        );
    }

    #[DataProvider('validOccurrenceTimes')]
    public function test_occurrence_at_or_before_creation_is_accepted(string $occurredAt): void
    {
        $movementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            [
                'ocurrido_en' => $occurredAt,
                'created_at' => self::CREATED_AT,
            ],
        );

        $this->assertNotNull($movementId);
    }

    public static function validOccurrenceTimes(): array
    {
        return [
            'equal' => [self::CREATED_AT],
            'earlier' => [self::EARLIER_AT],
        ];
    }

    public function test_occurrence_after_creation_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            [
                'ocurrido_en' => self::FUTURE_AT,
                'created_at' => self::CREATED_AT,
            ],
        );
    }

    #[DataProvider('invalidSnapshots')]
    public function test_invalid_inventory_movement_snapshot_is_rejected(
        string $column,
        string $value,
    ): void {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            [$column => $value],
        );
    }

    public static function invalidSnapshots(): array
    {
        return [
            'sku' => ['articulo_sku_snapshot', 'PAN_PRUEBA'],
            'name' => ['articulo_nombre_snapshot', '   '],
            'unit code' => ['unidad_codigo_snapshot', 'KG'],
            'unit symbol' => ['unidad_simbolo_snapshot', ' kg'],
        ];
    }

    public function test_inventory_movement_cannot_reverse_itself(): void
    {
        $originalId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides('venta', $this->origins),
        );
        $cancellationId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'anulacion_venta',
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides(
                'anulacion_venta',
                $this->origins,
                $originalId,
            ),
        );

        $this->expectException(QueryException::class);
        DB::table('movimientos_inventario')
            ->where('id', $cancellationId)
            ->update(['movimiento_revertido_id' => $cancellationId]);
    }

    private function validOverridesForType(string $type): array
    {
        $reversedMovementId = null;

        if (str_starts_with($type, 'anulacion_')) {
            $originalType = $this->originalInventoryMovementType($type);
            $reversedMovementId = $this->createInventoryMovement(
                $this->inventoryMovementArticleId($originalType, $this->origins),
                $this->userId,
                $originalType,
                $this->nextUuid(),
                $this->inventoryMovementCauseOverrides($originalType, $this->origins),
            );
        }

        return $this->inventoryMovementCauseOverrides(
            $type,
            $this->origins,
            $reversedMovementId,
        );
    }

    private function nextUuid(): string
    {
        return sprintf(
            '82000000-0000-4000-8000-%012d',
            $this->uuidSequence++,
        );
    }
}
