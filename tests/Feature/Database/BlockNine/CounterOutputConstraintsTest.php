<?php

namespace Tests\Feature\Database\BlockNine;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFiveRecords;
use Tests\Support\CreatesBlockNineRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockThreeRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class CounterOutputConstraintsTest extends TestCase
{
    use CreatesBlockFiveRecords;
    use CreatesBlockNineRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockThreeRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const UUID_THREE = '33333333-3333-4333-8333-333333333333';

    private const UUID_FOUR = '44444444-4444-4444-8444-444444444444';

    private const CREATED_AT = '2026-09-20 10:00:00-03';

    private const EARLIER_AT = '2026-09-20 09:00:00-03';

    private const LATER_AT = '2026-09-20 11:00:00-03';

    private int $articleId;

    private int $categoryId;

    private int $productionDetailId;

    private int $recipeId;

    private int $roleId;

    private int $unitId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->userId = $this->createUser($this->roleId, ['nombre_usuario' => 'salida_meson']);
        $this->categoryId = $this->createArticleCategory();
        $this->unitId = $this->createUnitOfMeasure();
        $this->articleId = $this->createArticle($this->categoryId, $this->unitId, [
            'sku' => 'pan_salida',
            'nombre' => 'Pan salida',
        ]);
        $this->recipeId = $this->createRecipe($this->articleId, ['activa' => true]);
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);
        $this->productionDetailId = $this->createProductionDetail($productionId, $this->articleId, [
            'articulo_sku_snapshot' => 'pan_salida',
            'articulo_nombre_snapshot' => 'Pan salida',
            'cantidad_teorica' => '10.000',
            'cantidad_real' => '10.000',
        ]);
    }

    public function test_counter_output_uses_database_timestamp_defaults(): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $output = DB::table('salidas_meson')->where('id', $outputId)->first();

        $this->assertNotNull($output);
        $this->assertSame($this->productionDetailId, $output->detalle_produccion_id);
        $this->assertSame(self::UUID_ONE, $output->clave_idempotencia);
        $this->assertSame('1.000', $output->cantidad);
        $this->assertNotNull($output->ocurrido_en);
        $this->assertNotNull($output->created_at);
        $this->assertSame($output->created_at, $output->ocurrido_en);
        $this->assertNull($output->motivo_ajuste_ocurrido_en);
    }

    #[DataProvider('validCounterOutputQuantities')]
    public function test_valid_counter_output_quantity_is_accepted(string $quantity): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['cantidad' => $quantity],
        );

        $this->assertSame($quantity, DB::table('salidas_meson')->where('id', $outputId)->value('cantidad'));
    }

    public static function validCounterOutputQuantities(): array
    {
        return [
            'positive integer' => ['2.000'],
            'fractional' => ['0.125'],
        ];
    }

    #[DataProvider('invalidCounterOutputQuantities')]
    public function test_invalid_counter_output_quantity_is_rejected(string $quantity): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['cantidad' => $quantity],
        );
    }

    public static function invalidCounterOutputQuantities(): array
    {
        return [
            'zero' => ['0.000'],
            'negative' => ['-0.001'],
            'NaN' => ['NaN'],
        ];
    }

    public function test_normal_counter_output_accepts_equal_fixed_timestamps(): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['ocurrido_en' => self::CREATED_AT, 'created_at' => self::CREATED_AT],
        );
        $output = DB::table('salidas_meson')->where('id', $outputId)->first();

        $this->assertSame($output->created_at, $output->ocurrido_en);
        $this->assertNull($output->motivo_ajuste_ocurrido_en);
    }

    public function test_normal_counter_output_rejects_distinct_timestamps_without_reason(): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['ocurrido_en' => self::EARLIER_AT, 'created_at' => self::CREATED_AT],
        );
    }

    public function test_backdated_counter_output_with_trimmed_reason_is_accepted(): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::EARLIER_AT,
                'created_at' => self::CREATED_AT,
                'motivo_ajuste_ocurrido_en' => 'Registro manual posterior',
            ],
        );

        $this->assertSame(
            'Registro manual posterior',
            DB::table('salidas_meson')->where('id', $outputId)->value('motivo_ajuste_ocurrido_en'),
        );
    }

    #[DataProvider('invalidBackdatingReasons')]
    public function test_invalid_backdating_reason_is_rejected(string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::EARLIER_AT,
                'created_at' => self::CREATED_AT,
                'motivo_ajuste_ocurrido_en' => $reason,
            ],
        );
    }

    public static function invalidBackdatingReasons(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Ajuste'],
            'trailing space' => ['Ajuste '],
        ];
    }

    #[DataProvider('invalidBackdatingChronologies')]
    public function test_adjustment_reason_requires_strictly_earlier_occurrence(string $occurredAt): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => $occurredAt,
                'created_at' => self::CREATED_AT,
                'motivo_ajuste_ocurrido_en' => 'Ajuste informado',
            ],
        );
    }

    public static function invalidBackdatingChronologies(): array
    {
        return [
            'equal to creation' => [self::CREATED_AT],
            'after creation' => [self::LATER_AT],
        ];
    }

    public function test_non_annulled_counter_output_has_empty_annulment_audit(): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $output = DB::table('salidas_meson')->where('id', $outputId)->first();

        $this->assertNull($output->anulada_en);
        $this->assertNull($output->usuario_anulador_id);
        $this->assertNull($output->motivo_anulacion);
    }

    public function test_annulled_counter_output_with_complete_audit_is_accepted(): void
    {
        $annullingUserId = $this->createDistinctUser('salida_anulador');
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
                'anulada_en' => self::LATER_AT,
                'usuario_anulador_id' => $annullingUserId,
                'motivo_anulacion' => 'Salida duplicada',
            ],
        );
        $output = DB::table('salidas_meson')->where('id', $outputId)->first();

        $this->assertNotNull($output->anulada_en);
        $this->assertSame($annullingUserId, $output->usuario_anulador_id);
        $this->assertSame('Salida duplicada', $output->motivo_anulacion);
    }

    #[DataProvider('invalidCounterOutputAnnulmentStructures')]
    public function test_partial_counter_output_annulment_audit_is_rejected(
        bool $withTimestamp,
        bool $withUser,
        bool $withReason,
    ): void {
        $annullingUserId = $withUser ? $this->createDistinctUser('salida_auditoria') : null;

        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
                'anulada_en' => $withTimestamp ? self::LATER_AT : null,
                'usuario_anulador_id' => $annullingUserId,
                'motivo_anulacion' => $withReason ? 'Motivo valido' : null,
            ],
        );
    }

    public static function invalidCounterOutputAnnulmentStructures(): array
    {
        return [
            'timestamp only' => [true, false, false],
            'user only' => [false, true, false],
            'reason only' => [false, false, true],
            'timestamp and user' => [true, true, false],
            'timestamp and reason' => [true, false, true],
            'user and reason' => [false, true, true],
        ];
    }

    #[DataProvider('validCounterOutputAnnulmentDates')]
    public function test_valid_counter_output_annulment_date_is_accepted(string $annulledAt): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
                'anulada_en' => $annulledAt,
                'usuario_anulador_id' => $this->userId,
                'motivo_anulacion' => 'Fecha valida',
            ],
        );

        $this->assertNotNull(DB::table('salidas_meson')->where('id', $outputId)->value('anulada_en'));
    }

    public static function validCounterOutputAnnulmentDates(): array
    {
        return [
            'equals creation' => [self::CREATED_AT],
            'after creation' => [self::LATER_AT],
        ];
    }

    public function test_counter_output_annulment_before_creation_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
                'anulada_en' => self::EARLIER_AT,
                'usuario_anulador_id' => $this->userId,
                'motivo_anulacion' => 'Fecha invalida',
            ],
        );
    }

    #[DataProvider('invalidCounterOutputAnnulmentReasons')]
    public function test_invalid_counter_output_annulment_reason_is_rejected(string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            [
                'ocurrido_en' => self::CREATED_AT,
                'created_at' => self::CREATED_AT,
                'anulada_en' => self::LATER_AT,
                'usuario_anulador_id' => $this->userId,
                'motivo_anulacion' => $reason,
            ],
        );
    }

    public static function invalidCounterOutputAnnulmentReasons(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Motivo'],
            'trailing space' => ['Motivo '],
        ];
    }

    #[DataProvider('validCounterOutputObservations')]
    public function test_valid_counter_output_observation_is_preserved(?string $observation): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['observacion' => $observation],
        );

        $this->assertSame($observation, DB::table('salidas_meson')->where('id', $outputId)->value('observacion'));
    }

    public static function validCounterOutputObservations(): array
    {
        return [
            'null' => [null],
            'trimmed text' => ['Entrega parcial'],
        ];
    }

    #[DataProvider('invalidCounterOutputObservations')]
    public function test_invalid_counter_output_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['observacion' => $observation],
        );
    }

    public static function invalidCounterOutputObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Observacion'],
            'trailing space' => ['Observacion '],
        ];
    }

    public function test_counter_output_cannot_replace_itself(): void
    {
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );

        $this->expectException(QueryException::class);
        DB::table('salidas_meson')->where('id', $outputId)->update(['salida_reemplazada_id' => $outputId]);
    }

    public function test_second_direct_replacement_of_same_output_is_rejected(): void
    {
        $originalId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
            ['salida_reemplazada_id' => $originalId],
        );

        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_THREE,
            ['salida_reemplazada_id' => $originalId],
        );
    }

    public function test_counter_output_replacement_chain_is_structurally_allowed(): void
    {
        $firstId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $secondId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
            ['salida_reemplazada_id' => $firstId],
        );
        $thirdId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_THREE,
            ['salida_reemplazada_id' => $secondId],
        );

        $this->assertSame($firstId, DB::table('salidas_meson')->where('id', $secondId)->value('salida_reemplazada_id'));
        $this->assertSame($secondId, DB::table('salidas_meson')->where('id', $thirdId)->value('salida_reemplazada_id'));
    }

    public function test_multiple_counter_outputs_can_have_null_replacement(): void
    {
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
        );

        $this->assertSame(2, DB::table('salidas_meson')->whereNull('salida_reemplazada_id')->count());
    }

    public function test_distinct_counter_output_idempotency_keys_are_accepted(): void
    {
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
        );

        $this->assertSame(2, DB::table('salidas_meson')->distinct()->count('clave_idempotencia'));
    }

    public function test_duplicate_counter_output_idempotency_key_is_rejected_globally(): void
    {
        $otherDetailId = $this->createAdditionalProductionDetail();
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );

        $this->expectException(QueryException::class);
        $this->createCounterOutput(
            $otherDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
    }

    public function test_multiple_partial_outputs_for_same_production_detail_are_accepted(): void
    {
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['cantidad' => '3.000'],
        );
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
            ['cantidad' => '4.000'],
        );

        $this->assertSame(2, DB::table('salidas_meson')->where('detalle_produccion_id', $this->productionDetailId)->count());
    }

    public function test_counter_output_sum_can_exceed_produced_quantity(): void
    {
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
            ['cantidad' => '8.000'],
        );
        $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
            ['cantidad' => '8.000'],
        );

        $this->assertSame('10.000', DB::table('detalle_producciones')->where('id', $this->productionDetailId)->value('cantidad_real'));
        $this->assertSame(
            '16.000',
            DB::table('salidas_meson')->where('detalle_produccion_id', $this->productionDetailId)->sum('cantidad'),
        );
    }

    public function test_counter_outputs_on_draft_and_annulled_productions_are_structurally_allowed(): void
    {
        $annulledDetailId = $this->createProductionDetailForState('anulada');
        $draftOutputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $this->userId,
            self::UUID_ONE,
        );
        $annulledOutputId = $this->createCounterOutput(
            $annulledDetailId,
            $this->userId,
            $this->userId,
            self::UUID_TWO,
        );

        $states = DB::table('salidas_meson')
            ->join('detalle_producciones', 'detalle_producciones.id', '=', 'salidas_meson.detalle_produccion_id')
            ->join('producciones', 'producciones.id', '=', 'detalle_producciones.produccion_id')
            ->whereIn('salidas_meson.id', [$draftOutputId, $annulledOutputId])
            ->orderBy('producciones.estado')
            ->pluck('producciones.estado')
            ->all();

        $this->assertSame(['anulada', 'borrador'], $states);
    }

    #[DataProvider('counterOutputUserRelationships')]
    public function test_counter_output_accepts_supported_user_relationship(bool $sameUser): void
    {
        $registrarId = $sameUser ? $this->userId : $this->createDistinctUser('salida_registrador');
        $outputId = $this->createCounterOutput(
            $this->productionDetailId,
            $this->userId,
            $registrarId,
            self::UUID_ONE,
        );
        $output = DB::table('salidas_meson')->where('id', $outputId)->first();

        $this->assertSame($sameUser, $output->usuario_responsable_id === $output->usuario_registrador_id);
    }

    public static function counterOutputUserRelationships(): array
    {
        return [
            'same responsible and registrar' => [true],
            'distinct responsible and registrar' => [false],
        ];
    }

    private function createAdditionalProductionDetail(): int
    {
        $productionId = $this->createProduction($this->recipeId, $this->userId, $this->userId);

        return $this->createProductionDetail($productionId, $this->articleId, [
            'articulo_sku_snapshot' => 'pan_salida',
            'articulo_nombre_snapshot' => 'Pan salida',
            'cantidad_teorica' => '10.000',
            'cantidad_real' => '10.000',
        ]);
    }

    private function createProductionDetailForState(string $state): int
    {
        $overrides = [];

        if ($state === 'anulada') {
            $overrides = [
                'estado' => 'anulada',
                'confirmada_en' => '2026-09-20 09:00:00-03',
                'usuario_confirmador_id' => $this->userId,
                'anulada_en' => '2026-09-20 10:00:00-03',
                'usuario_anulador_id' => $this->userId,
                'motivo_anulacion' => 'Produccion anulada',
                'created_at' => '2026-09-20 08:00:00-03',
            ];
        }

        $productionId = $this->createProduction(
            $this->recipeId,
            $this->userId,
            $this->userId,
            $overrides,
        );

        return $this->createProductionDetail($productionId, $this->articleId, [
            'articulo_sku_snapshot' => 'pan_salida',
            'articulo_nombre_snapshot' => 'Pan salida',
            'cantidad_teorica' => '10.000',
            'cantidad_real' => '10.000',
        ]);
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Salida',
            'nombre_usuario' => $username,
        ]);
    }
}
