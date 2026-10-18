<?php

namespace Tests\Feature\Database\BlockEleven;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockEightRecords;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockEightRecords;
    use CreatesBlockElevenRecords;
    use CreatesBlockFiveRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const CONFIRMED_AT = '2026-09-20 10:00:00-03';

    private const ANNULLED_AT = '2026-09-20 11:00:00-03';

    private const CREATED_AT = '2026-09-20 09:00:00-03';

    private const MISSING_ID = 9_999_999_999;

    private array $origins;

    private int $userId;

    private int $uuidSequence = 300;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser(
            $this->createRole(),
            ['nombre_usuario' => 'integridad_b11'],
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

    #[DataProvider('missingReceiptRelationships')]
    public function test_receipt_rejects_missing_relationship(string $relationship): void
    {
        $this->assertForeignKeyViolation(fn () => $this->createReceipt(
            $this->userId,
            $this->nextUuid(),
            $this->receiptOverridesForMissingRelationship($relationship),
        ));
    }

    public static function missingReceiptRelationships(): array
    {
        return [
            'supplier' => ['supplier'],
            'contributor' => ['contributor'],
            'creator' => ['creator'],
            'confirming user' => ['confirming user'],
            'annulling user' => ['annulling user'],
        ];
    }

    #[DataProvider('missingReceiptDetailRelationships')]
    public function test_receipt_detail_rejects_missing_relationship(string $relationship): void
    {
        $receiptId = DB::table('detalle_recepciones_mercaderia')
            ->where('id', $this->origins['detalle_recepcion_mercaderia_id'])
            ->value('recepcion_mercaderia_id');
        $receiptId = $relationship === 'receipt' ? self::MISSING_ID : (int) $receiptId;
        $articleId = $relationship === 'article'
            ? self::MISSING_ID
            : $this->origins['article_id'];

        $this->assertForeignKeyViolation(fn () => $this->createReceiptDetail(
            $receiptId,
            $articleId,
        ));
    }

    public static function missingReceiptDetailRelationships(): array
    {
        return [
            'receipt' => ['receipt'],
            'article' => ['article'],
        ];
    }

    public function test_cost_history_rejects_missing_receipt_detail(): void
    {
        $this->assertForeignKeyViolation(fn () => $this->createArticleCostHistory(
            $this->origins['article_id'],
            $this->userId,
            ['detalle_recepcion_mercaderia_id' => self::MISSING_ID],
        ));
    }

    #[DataProvider('missingMovementBaseRelationships')]
    public function test_inventory_movement_rejects_missing_base_relationship(
        string $relationship,
    ): void {
        $articleId = $relationship === 'article'
            ? self::MISSING_ID
            : $this->origins['article_id'];
        $userId = $relationship === 'user' ? self::MISSING_ID : $this->userId;
        $overrides = $relationship === 'authorizer'
            ? ['usuario_autorizador_id' => self::MISSING_ID]
            : [];

        $this->assertForeignKeyViolation(fn () => $this->createInventoryMovement(
            $articleId,
            $userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
            $overrides,
        ));
    }

    public static function missingMovementBaseRelationships(): array
    {
        return [
            'article' => ['article'],
            'user' => ['user'],
            'authorizer' => ['authorizer'],
        ];
    }

    #[DataProvider('missingMovementOrigins')]
    public function test_inventory_movement_rejects_missing_causal_origin(
        string $type,
        string $column,
        bool $usesComponentArticle,
    ): void {
        $articleId = $usesComponentArticle
            ? $this->origins['component_article_id']
            : $this->origins['article_id'];

        $this->assertForeignKeyViolation(fn () => $this->createInventoryMovement(
            $articleId,
            $this->userId,
            $type,
            $this->nextUuid(),
            [$column => self::MISSING_ID],
        ));
    }

    public static function missingMovementOrigins(): array
    {
        return [
            'sale detail' => ['venta', 'detalle_venta_id', false],
            'receipt detail' => ['recepcion', 'detalle_recepcion_mercaderia_id', false],
            'production detail' => ['produccion_entrada', 'detalle_produccion_id', false],
            'production consumption' => ['produccion_consumo', 'consumo_produccion_id', true],
            'waste' => ['merma', 'merma_id', false],
            'inventory count detail' => ['ajuste_conteo_positivo', 'detalle_conteo_inventario_id', false],
        ];
    }

    public function test_inventory_cancellation_rejects_missing_reversed_movement(): void
    {
        $this->assertForeignKeyViolation(fn () => $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'anulacion_venta',
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides(
                'anulacion_venta',
                $this->origins,
                self::MISSING_ID,
            ),
        ));
    }

    public function test_inventory_count_detail_rejects_missing_observed_movement(): void
    {
        $this->assertForeignKeyViolation(fn () => DB::table('detalle_conteos_inventario')
            ->where('id', $this->origins['detalle_conteo_inventario_id'])
            ->update([
                'cantidad_contada' => '1.000',
                'contada_en' => self::CONFIRMED_AT,
                'movimiento_inventario_observado_id' => self::MISSING_ID,
            ]));
    }

    public function test_daily_stock_rejects_missing_article(): void
    {
        $this->assertForeignKeyViolation(fn () => $this->createStockDaily(self::MISSING_ID));
    }

    public function test_daily_stock_rejects_missing_last_movement(): void
    {
        $this->assertForeignKeyViolation(fn () => $this->createStockDaily(
            $this->origins['article_id'],
            ['ultimo_movimiento_id' => self::MISSING_ID],
        ));
    }

    public function test_receipt_detail_referenced_by_cost_history_cannot_be_deleted(): void
    {
        $detailId = $this->origins['detalle_recepcion_mercaderia_id'];
        $this->createArticleCostHistory(
            $this->origins['article_id'],
            $this->userId,
            ['detalle_recepcion_mercaderia_id' => $detailId],
        );

        $this->assertForeignKeyViolation(
            fn () => DB::table('detalle_recepciones_mercaderia')->where('id', $detailId)->delete(),
            '23001',
        );
    }

    public function test_movement_referenced_by_inventory_count_detail_cannot_be_deleted(): void
    {
        $movementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
        );
        DB::table('detalle_conteos_inventario')
            ->where('id', $this->origins['detalle_conteo_inventario_id'])
            ->update([
                'cantidad_contada' => '1.000',
                'contada_en' => self::CONFIRMED_AT,
                'movimiento_inventario_observado_id' => $movementId,
            ]);

        $this->assertForeignKeyViolation(
            fn () => DB::table('movimientos_inventario')->where('id', $movementId)->delete(),
            '23001',
        );
    }

    public function test_movement_referenced_by_daily_stock_cannot_be_deleted(): void
    {
        $movementId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'ajuste_manual_entrada',
            $this->nextUuid(),
        );
        $this->createStockDaily($this->origins['article_id'], [
            'ultimo_movimiento_id' => $movementId,
        ]);

        $this->assertForeignKeyViolation(
            fn () => DB::table('movimientos_inventario')->where('id', $movementId)->delete(),
            '23001',
        );
    }

    public function test_original_movement_referenced_by_cancellation_cannot_be_deleted(): void
    {
        $originalId = $this->createInventoryMovement(
            $this->origins['article_id'],
            $this->userId,
            'venta',
            $this->nextUuid(),
            $this->inventoryMovementCauseOverrides('venta', $this->origins),
        );
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

        $this->assertForeignKeyViolation(
            fn () => DB::table('movimientos_inventario')->where('id', $originalId)->delete(),
            '23001',
        );
    }

    private function receiptOverridesForMissingRelationship(string $relationship): array
    {
        return match ($relationship) {
            'supplier' => [
                'tipo_origen' => 'proveedor',
                'proveedor_id' => self::MISSING_ID,
                'origen_detalle' => null,
            ],
            'contributor' => [
                'tipo_origen' => 'interno',
                'usuario_aportante_id' => self::MISSING_ID,
                'origen_detalle' => null,
            ],
            'creator' => ['usuario_creador_id' => self::MISSING_ID],
            'confirming user' => [
                'estado' => 'confirmada',
                'usuario_confirmador_id' => self::MISSING_ID,
                'confirmada_en' => self::CONFIRMED_AT,
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
            ],
            'annulling user' => [
                'estado' => 'anulada',
                'usuario_confirmador_id' => $this->userId,
                'confirmada_en' => self::CONFIRMED_AT,
                'usuario_anulador_id' => self::MISSING_ID,
                'anulada_en' => self::ANNULLED_AT,
                'motivo_anulacion' => 'Relacion inexistente',
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
            ],
        };
    }

    private function assertForeignKeyViolation(
        Closure $operation,
        string $expectedSqlState = '23503',
    ): void {
        try {
            $operation();
            $this->fail('Expected a PostgreSQL foreign-key violation.');
        } catch (QueryException $exception) {
            $this->assertSame($expectedSqlState, (string) $exception->getCode());
        }
    }

    private function nextUuid(): string
    {
        return sprintf(
            '87000000-0000-4000-8000-%012d',
            $this->uuidSequence++,
        );
    }
}
