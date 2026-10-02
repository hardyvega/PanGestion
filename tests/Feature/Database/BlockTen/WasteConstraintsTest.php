<?php

namespace Tests\Feature\Database\BlockTen;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class WasteConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const UUID_THREE = '33333333-3333-4333-8333-333333333333';

    private const CREATED_AT = '2026-09-20 10:00:00-03';

    private const EARLIER_AT = '2026-09-20 09:00:00-03';

    private const LATER_AT = '2026-09-20 11:00:00-03';

    private int $articleId;

    private int $categoryId;

    private int $roleId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->userId = $this->createUser($this->roleId, ['nombre_usuario' => 'merma_base']);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId);
    }

    public function test_waste_can_be_created_normally_with_database_defaults(): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);
        $waste = DB::table('mermas')->where('id', $wasteId)->first();

        $this->assertNotNull($waste);
        $this->assertSame($this->articleId, $waste->articulo_id);
        $this->assertSame($this->userId, $waste->usuario_registrador_id);
        $this->assertNull($waste->usuario_anulador_id);
        $this->assertNull($waste->merma_reemplazada_id);
        $this->assertSame(self::UUID_ONE, $waste->clave_idempotencia);
        $this->assertSame('pan_prueba', $waste->articulo_sku_snapshot);
        $this->assertSame('Pan de prueba', $waste->articulo_nombre_snapshot);
        $this->assertSame('kg', $waste->unidad_codigo_snapshot);
        $this->assertSame('kg', $waste->unidad_simbolo_snapshot);
        $this->assertSame('vencimiento', $waste->tipo_merma);
        $this->assertNull($waste->motivo_detalle);
        $this->assertSame('1.000', $waste->cantidad);
        $this->assertNotNull($waste->ocurrido_en);
        $this->assertNotNull($waste->created_at);
        $this->assertSame($waste->created_at, $waste->ocurrido_en);
        $this->assertNull($waste->motivo_ajuste_ocurrido_en);
        $this->assertNull($waste->anulada_en);
        $this->assertNull($waste->motivo_anulacion);
    }

    public function test_inactive_article_is_structurally_allowed_in_waste(): void
    {
        $inactiveArticleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_merma_inactivo',
            'nombre' => 'Pan merma inactivo',
            'activo' => false,
        ]);
        $wasteId = $this->createWaste($inactiveArticleId, $this->userId, self::UUID_ONE, [
            'articulo_sku_snapshot' => 'pan_merma_inactivo',
            'articulo_nombre_snapshot' => 'Pan merma inactivo',
        ]);

        $this->assertFalse((bool) DB::table('articulos')->where('id', $inactiveArticleId)->value('activo'));
        $this->assertSame($inactiveArticleId, DB::table('mermas')->where('id', $wasteId)->value('articulo_id'));
    }

    public function test_distinct_waste_idempotency_keys_are_accepted(): void
    {
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);
        $this->createWaste($this->articleId, $this->userId, self::UUID_TWO);

        $this->assertSame(2, DB::table('mermas')->distinct()->count('clave_idempotencia'));
    }

    public function test_duplicate_waste_idempotency_key_is_rejected_globally(): void
    {
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);

        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);
    }

    public function test_valid_waste_snapshots_are_preserved(): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'articulo_sku_snapshot' => 'pan_integral_01',
            'articulo_nombre_snapshot' => 'Pan integral',
            'unidad_codigo_snapshot' => 'unidad',
            'unidad_simbolo_snapshot' => 'Un',
        ]);
        $waste = DB::table('mermas')->where('id', $wasteId)->first();

        $this->assertSame('pan_integral_01', $waste->articulo_sku_snapshot);
        $this->assertSame('Pan integral', $waste->articulo_nombre_snapshot);
        $this->assertSame('unidad', $waste->unidad_codigo_snapshot);
        $this->assertSame('Un', $waste->unidad_simbolo_snapshot);
    }

    #[DataProvider('invalidWasteSnapshots')]
    public function test_invalid_waste_snapshot_is_rejected(string $column, string $value): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [$column => $value]);
    }

    public static function invalidWasteSnapshots(): array
    {
        return [
            'sku empty' => ['articulo_sku_snapshot', ''],
            'sku leading space' => ['articulo_sku_snapshot', ' pan_prueba'],
            'sku trailing space' => ['articulo_sku_snapshot', 'pan_prueba '],
            'sku uppercase' => ['articulo_sku_snapshot', 'PAN_PRUEBA'],
            'sku invalid format' => ['articulo_sku_snapshot', 'pan.prueba'],
            'name empty' => ['articulo_nombre_snapshot', ''],
            'name spaces only' => ['articulo_nombre_snapshot', '   '],
            'name leading space' => ['articulo_nombre_snapshot', ' Pan de prueba'],
            'name trailing space' => ['articulo_nombre_snapshot', 'Pan de prueba '],
            'unit code empty' => ['unidad_codigo_snapshot', ''],
            'unit code leading space' => ['unidad_codigo_snapshot', ' kg'],
            'unit code trailing space' => ['unidad_codigo_snapshot', 'kg '],
            'unit code uppercase' => ['unidad_codigo_snapshot', 'KG'],
            'unit code invalid format' => ['unidad_codigo_snapshot', '1kg'],
            'unit symbol empty' => ['unidad_simbolo_snapshot', ''],
            'unit symbol spaces only' => ['unidad_simbolo_snapshot', '   '],
            'unit symbol leading space' => ['unidad_simbolo_snapshot', ' Kg'],
            'unit symbol trailing space' => ['unidad_simbolo_snapshot', 'Kg '],
        ];
    }

    #[DataProvider('supportedWasteTypes')]
    public function test_supported_waste_type_is_accepted(string $type, ?string $detail): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'tipo_merma' => $type,
            'motivo_detalle' => $detail,
        ]);

        $this->assertSame($type, DB::table('mermas')->where('id', $wasteId)->value('tipo_merma'));
    }

    public static function supportedWasteTypes(): array
    {
        return [
            'expiration' => ['vencimiento', null],
            'damage' => ['danio', null],
            'production error' => ['error_produccion', null],
            'contamination' => ['contaminacion', null],
            'other' => ['otro', 'Rotura accidental'],
        ];
    }

    #[DataProvider('invalidWasteTypes')]
    public function test_invalid_waste_type_is_rejected(string $type): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, ['tipo_merma' => $type]);
    }

    public static function invalidWasteTypes(): array
    {
        return [
            'uppercase' => ['VENCIMIENTO'],
            'legacy spelling' => ['dano'],
            'unknown' => ['desconocido'],
            'empty' => [''],
        ];
    }

    #[DataProvider('validWasteQuantities')]
    public function test_valid_waste_quantity_is_accepted(string $quantity): void
    {
        $wasteId = $this->createWaste(
            $this->articleId,
            $this->userId,
            self::UUID_ONE,
            ['cantidad' => $quantity],
        );

        $this->assertSame($quantity, DB::table('mermas')->where('id', $wasteId)->value('cantidad'));
    }

    public static function validWasteQuantities(): array
    {
        return [
            'positive integer' => ['2.000'],
            'fractional' => ['0.125'],
        ];
    }

    #[DataProvider('invalidWasteQuantities')]
    public function test_invalid_waste_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste(
            $this->articleId,
            $this->userId,
            self::UUID_ONE,
            ['cantidad' => $quantity],
        );
    }

    public static function invalidWasteQuantities(): array
    {
        return [
            'zero' => ['0.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    #[DataProvider('invalidOtherWasteDetails')]
    public function test_other_waste_requires_valid_detail(?string $detail): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'tipo_merma' => 'otro',
            'motivo_detalle' => $detail,
        ]);
    }

    public static function invalidOtherWasteDetails(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Detalle'],
            'trailing space' => ['Detalle '],
        ];
    }

    #[DataProvider('validNonOtherWasteDetails')]
    public function test_non_other_waste_accepts_supported_detail(?string $detail): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'tipo_merma' => 'danio',
            'motivo_detalle' => $detail,
        ]);

        $this->assertSame($detail, DB::table('mermas')->where('id', $wasteId)->value('motivo_detalle'));
    }

    public static function validNonOtherWasteDetails(): array
    {
        return [
            'null' => [null],
            'trimmed text' => ['Envase roto'],
        ];
    }

    #[DataProvider('invalidNonOtherWasteDetails')]
    public function test_non_other_waste_rejects_invalid_informed_detail(string $detail): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'tipo_merma' => 'danio',
            'motivo_detalle' => $detail,
        ]);
    }

    public static function invalidNonOtherWasteDetails(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Detalle'],
            'trailing space' => ['Detalle '],
        ];
    }

    public function test_normal_waste_accepts_equal_fixed_timestamps_without_adjustment_reason(): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
        ]);
        $waste = DB::table('mermas')->where('id', $wasteId)->first();

        $this->assertSame($waste->created_at, $waste->ocurrido_en);
        $this->assertNull($waste->motivo_ajuste_ocurrido_en);
    }

    public function test_backdated_waste_with_trimmed_adjustment_reason_is_accepted(): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::EARLIER_AT,
            'created_at' => self::CREATED_AT,
            'motivo_ajuste_ocurrido_en' => 'Registro posterior autorizado',
        ]);

        $this->assertSame(
            'Registro posterior autorizado',
            DB::table('mermas')->where('id', $wasteId)->value('motivo_ajuste_ocurrido_en'),
        );
    }

    public function test_backdated_waste_without_adjustment_reason_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::EARLIER_AT,
            'created_at' => self::CREATED_AT,
        ]);
    }

    public function test_future_waste_occurrence_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::LATER_AT,
            'created_at' => self::CREATED_AT,
            'motivo_ajuste_ocurrido_en' => 'Fecha futura informada',
        ]);
    }

    public function test_normal_waste_with_adjustment_reason_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
            'motivo_ajuste_ocurrido_en' => 'Motivo innecesario',
        ]);
    }

    #[DataProvider('invalidWasteAdjustmentReasons')]
    public function test_invalid_required_waste_adjustment_reason_is_rejected(string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::EARLIER_AT,
            'created_at' => self::CREATED_AT,
            'motivo_ajuste_ocurrido_en' => $reason,
        ]);
    }

    public static function invalidWasteAdjustmentReasons(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Ajuste'],
            'trailing space' => ['Ajuste '],
        ];
    }

    public function test_annulled_waste_with_complete_audit_is_accepted(): void
    {
        $annullingUserId = $this->createDistinctUser('merma_anulador');
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
            'usuario_anulador_id' => $annullingUserId,
            'anulada_en' => self::LATER_AT,
            'motivo_anulacion' => 'Registro duplicado',
        ]);
        $waste = DB::table('mermas')->where('id', $wasteId)->first();

        $this->assertSame($annullingUserId, $waste->usuario_anulador_id);
        $this->assertNotNull($waste->anulada_en);
        $this->assertSame('Registro duplicado', $waste->motivo_anulacion);
    }

    #[DataProvider('invalidWasteAnnulmentStructures')]
    public function test_partial_waste_annulment_audit_is_rejected(
        bool $withUser,
        bool $withTimestamp,
        bool $withReason,
    ): void {
        $annullingUserId = $withUser ? $this->createDistinctUser('merma_auditoria') : null;

        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
            'usuario_anulador_id' => $annullingUserId,
            'anulada_en' => $withTimestamp ? self::LATER_AT : null,
            'motivo_anulacion' => $withReason ? 'Motivo valido' : null,
        ]);
    }

    public static function invalidWasteAnnulmentStructures(): array
    {
        return [
            'user only' => [true, false, false],
            'timestamp only' => [false, true, false],
            'reason only' => [false, false, true],
            'user and timestamp' => [true, true, false],
            'user and reason' => [true, false, true],
            'timestamp and reason' => [false, true, true],
        ];
    }

    #[DataProvider('validWasteAnnulmentDates')]
    public function test_valid_waste_annulment_date_is_accepted(string $annulledAt): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
            'usuario_anulador_id' => $this->userId,
            'anulada_en' => $annulledAt,
            'motivo_anulacion' => 'Fecha valida',
        ]);

        $this->assertNotNull(DB::table('mermas')->where('id', $wasteId)->value('anulada_en'));
    }

    public static function validWasteAnnulmentDates(): array
    {
        return [
            'equals creation' => [self::CREATED_AT],
            'after creation' => [self::LATER_AT],
        ];
    }

    public function test_waste_annulment_before_creation_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
            'usuario_anulador_id' => $this->userId,
            'anulada_en' => self::EARLIER_AT,
            'motivo_anulacion' => 'Fecha invalida',
        ]);
    }

    #[DataProvider('invalidWasteAnnulmentReasons')]
    public function test_invalid_waste_annulment_reason_is_rejected(string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
            'usuario_anulador_id' => $this->userId,
            'anulada_en' => self::LATER_AT,
            'motivo_anulacion' => $reason,
        ]);
    }

    public static function invalidWasteAnnulmentReasons(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Motivo'],
            'trailing space' => ['Motivo '],
        ];
    }

    public function test_waste_cannot_replace_itself(): void
    {
        $wasteId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);

        $this->expectException(QueryException::class);
        DB::table('mermas')->where('id', $wasteId)->update(['merma_reemplazada_id' => $wasteId]);
    }

    public function test_one_direct_waste_replacement_is_accepted(): void
    {
        $originalId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);
        $replacementId = $this->createWaste($this->articleId, $this->userId, self::UUID_TWO, [
            'merma_reemplazada_id' => $originalId,
        ]);

        $this->assertSame(
            $originalId,
            DB::table('mermas')->where('id', $replacementId)->value('merma_reemplazada_id'),
        );
    }

    public function test_second_direct_replacement_of_same_waste_is_rejected(): void
    {
        $originalId = $this->createWaste($this->articleId, $this->userId, self::UUID_ONE);
        $this->createWaste($this->articleId, $this->userId, self::UUID_TWO, [
            'merma_reemplazada_id' => $originalId,
        ]);

        $this->expectException(QueryException::class);
        $this->createWaste($this->articleId, $this->userId, self::UUID_THREE, [
            'merma_reemplazada_id' => $originalId,
        ]);
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Merma',
            'nombre_usuario' => $username,
        ]);
    }
}
