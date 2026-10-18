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

class InventoryLedgerOriginConstraintsTest extends TestCase
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

    private array $origins;

    private int $userId;

    private int $uuidSequence = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'origen_ledger'],
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

    #[DataProvider('causalMovementTypes')]
    public function test_each_movement_type_accepts_its_exact_causal_structure(string $type): void
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

    public static function causalMovementTypes(): array
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

    public function test_sale_without_sale_detail_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
        );
    }

    public function test_sale_with_wrong_cause_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            ['detalle_recepcion_mercaderia_id' => $this->origins['detalle_recepcion_mercaderia_id']],
        );
    }

    public function test_sale_with_two_causes_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            [
                'detalle_venta_id' => $this->origins['detalle_venta_id'],
                'detalle_recepcion_mercaderia_id' => $this->origins['detalle_recepcion_mercaderia_id'],
            ],
        );
    }

    public function test_original_movement_with_reversed_movement_reference_is_rejected(): void
    {
        $otherMovementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
        );

        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides(
                'venta',
                $this->origins,
                $otherMovementId,
            ),
        );
    }

    public function test_receipt_cancellation_without_receipt_detail_is_rejected(): void
    {
        $originalId = $this->createOriginalForCancellation('anulacion_recepcion');

        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'anulacion_recepcion',
            $this->nextUuid(),
            ['movimiento_revertido_id' => $originalId],
        );
    }

    public function test_production_entry_with_consumption_cause_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'produccion_entrada',
            $this->nextUuid(),
            ['consumo_produccion_id' => $this->origins['consumo_produccion_id']],
        );
    }

    public function test_waste_with_extra_cause_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'merma',
            $this->nextUuid(),
            [
                'merma_id' => $this->origins['merma_id'],
                'detalle_venta_id' => $this->origins['detalle_venta_id'],
            ],
        );
    }

    public function test_count_adjustment_with_extra_cause_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_conteo_positivo',
            $this->nextUuid(),
            [
                'detalle_conteo_inventario_id' => $this->origins['detalle_conteo_inventario_id'],
                'detalle_venta_id' => $this->origins['detalle_venta_id'],
            ],
        );
    }

    public function test_manual_adjustment_with_causal_reference_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            ['detalle_venta_id' => $this->origins['detalle_venta_id']],
        );
    }

    public function test_opening_balance_with_causal_reference_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'saldo_inicial',
            $this->nextUuid(),
            ['detalle_venta_id' => $this->origins['detalle_venta_id']],
        );
    }

    #[DataProvider('cancellationTypes')]
    public function test_each_cancellation_requires_its_causal_origin(string $type): void
    {
        $originalId = $this->createOriginalForCancellation($type);

        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->inventoryMovementArticleId($type, $this->origins),
            $this->userId,
            $type,
            $this->nextUuid(),
            ['movimiento_revertido_id' => $originalId],
        );
    }

    #[DataProvider('cancellationTypes')]
    public function test_each_cancellation_requires_reversed_movement(string $type): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->inventoryMovementArticleId($type, $this->origins),
            $this->userId,
            $type,
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides($type, $this->origins),
        );
    }

    public static function cancellationTypes(): array
    {
        return [
            'sale' => ['anulacion_venta'],
            'receipt' => ['anulacion_recepcion'],
            'production entry' => ['anulacion_produccion_entrada'],
            'production consumption' => ['anulacion_produccion_consumo'],
            'waste' => ['anulacion_merma'],
        ];
    }

    #[DataProvider('partialUniqueOrigins')]
    public function test_each_original_causal_origin_is_unique(
        string $firstType,
        string $secondType,
    ): void {
        $this->createInventoryMovement(
            $this->inventoryMovementArticleId($firstType, $this->origins),
            $this->userId,
            $firstType,
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides($firstType, $this->origins),
        );

        $this->expectException(QueryException::class);
        $this->createInventoryMovement(
            $this->inventoryMovementArticleId($secondType, $this->origins),
            $this->userId,
            $secondType,
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides($secondType, $this->origins),
        );
    }

    public static function partialUniqueOrigins(): array
    {
        return [
            'sale detail' => ['venta', 'venta'],
            'receipt detail' => ['recepcion', 'recepcion'],
            'production detail' => ['produccion_entrada', 'produccion_entrada'],
            'production consumption' => ['produccion_consumo', 'produccion_consumo'],
            'waste' => ['merma', 'merma'],
            'shared count adjustment' => ['ajuste_conteo_positivo', 'ajuste_conteo_negativo'],
            'opening balance by article' => ['saldo_inicial', 'saldo_inicial'],
        ];
    }

    #[DataProvider('originalAndCancellationTypes')]
    public function test_cancellation_does_not_collide_with_original_partial_unique_index(
        string $originalType,
        string $cancellationType,
    ): void {
        $originalId = $this->createInventoryMovement(
            $this->inventoryMovementArticleId($originalType, $this->origins),
            $this->userId,
            $originalType,
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides($originalType, $this->origins),
        );
        $cancellationId = $this->createInventoryMovement(
            $this->inventoryMovementArticleId($cancellationType, $this->origins),
            $this->userId,
            $cancellationType,
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides(
                $cancellationType,
                $this->origins,
                $originalId,
            ),
        );

        $this->assertNotSame($originalId, $cancellationId);
    }

    public static function originalAndCancellationTypes(): array
    {
        return [
            'sale' => ['venta', 'anulacion_venta'],
            'receipt' => ['recepcion', 'anulacion_recepcion'],
            'production entry' => ['produccion_entrada', 'anulacion_produccion_entrada'],
            'production consumption' => ['produccion_consumo', 'anulacion_produccion_consumo'],
            'waste' => ['merma', 'anulacion_merma'],
        ];
    }

    public function test_original_movement_can_only_be_reversed_once(): void
    {
        $originalId = $this->createOriginalForCancellation('anulacion_venta');
        $this->createInventoryMovement(
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
        $this->createInventoryMovement(
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
    }

    private function validOverridesForType(string $type): array
    {
        $reversedMovementId = str_starts_with($type, 'anulacion_')
            ? $this->createOriginalForCancellation($type)
            : null;

        return $this->inventoryMovementCauseOverrides(
            $type,
            $this->origins,
            $reversedMovementId,
        );
    }

    private function createOriginalForCancellation(string $cancellationType): int
    {
        $originalType = $this->originalInventoryMovementType($cancellationType);

        return $this->createInventoryMovement(
            $this->inventoryMovementArticleId($originalType, $this->origins),
            $this->userId,
            $originalType,
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides($originalType, $this->origins),
        );
    }

    private function nextUuid(): string
    {
        return sprintf(
            '83000000-0000-4000-8000-%012d',
            $this->uuidSequence++,
        );
    }
}
