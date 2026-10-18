<?php

namespace Tests\Feature\Database\BlockEleven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockElevenRecords;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

class ReceiptConstraintsTest extends TestCase
{
    use CreatesBlockElevenRecords;
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    private const UUID_ONE = '11111111-1111-4111-8111-111111111111';

    private const UUID_TWO = '22222222-2222-4222-8222-222222222222';

    private const CREATED_AT = '2026-09-20 09:00:00-03';

    private const EARLIER_AT = '2026-09-20 08:00:00-03';

    private const CONFIRMED_AT = '2026-09-20 10:00:00-03';

    private const ANNULLED_AT = '2026-09-20 11:00:00-03';

    private const FUTURE_AT = '2026-09-20 12:00:00-03';

    private int $otherUserId;

    private int $supplierId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = $this->createRole();
        $this->userId = $this->createUser($roleId, ['nombre_usuario' => 'recepcion_base']);
        $this->otherUserId = $this->createUser($roleId, ['nombre_usuario' => 'recepcion_otro']);
        $this->supplierId = $this->createSupplier();
    }

    #[DataProvider('validOrigins')]
    public function test_valid_receipt_origin_is_accepted(
        string $origin,
        bool $withSupplier,
        bool $withContributor,
        ?string $detail,
    ): void {
        $receiptId = $this->createReceipt($this->userId, self::UUID_ONE, [
            'tipo_origen' => $origin,
            'proveedor_id' => $withSupplier ? $this->supplierId : null,
            'usuario_aportante_id' => $withContributor ? $this->otherUserId : null,
            'origen_detalle' => $detail,
        ]);

        $this->assertSame($origin, DB::table('recepciones_mercaderia')->where('id', $receiptId)->value('tipo_origen'));
    }

    public static function validOrigins(): array
    {
        return [
            'supplier' => ['proveedor', true, false, null],
            'internal' => ['interno', false, true, null],
            'other authorized' => ['otro_autorizado', false, false, 'Donacion autorizada'],
        ];
    }

    #[DataProvider('invalidOrigins')]
    public function test_invalid_receipt_origin_structure_is_rejected(
        string $origin,
        bool $withSupplier,
        bool $withContributor,
        ?string $detail,
    ): void {
        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE, [
            'tipo_origen' => $origin,
            'proveedor_id' => $withSupplier ? $this->supplierId : null,
            'usuario_aportante_id' => $withContributor ? $this->otherUserId : null,
            'origen_detalle' => $detail,
        ]);
    }

    public static function invalidOrigins(): array
    {
        return [
            'invalid type' => ['donacion', false, false, 'Donacion'],
            'supplier without supplier' => ['proveedor', false, false, null],
            'supplier with contributor' => ['proveedor', true, true, null],
            'supplier with detail' => ['proveedor', true, false, 'Detalle'],
            'internal without contributor' => ['interno', false, false, null],
            'internal with supplier' => ['interno', true, true, null],
            'internal with detail' => ['interno', false, true, 'Detalle'],
            'other with supplier' => ['otro_autorizado', true, false, 'Detalle'],
            'other with contributor' => ['otro_autorizado', false, true, 'Detalle'],
            'other without detail' => ['otro_autorizado', false, false, null],
            'other with empty detail' => ['otro_autorizado', false, false, ''],
            'other with spaces' => ['otro_autorizado', false, false, '   '],
            'other with leading space' => ['otro_autorizado', false, false, ' Detalle'],
            'other with trailing space' => ['otro_autorizado', false, false, 'Detalle '],
        ];
    }

    #[DataProvider('validLifecycleStates')]
    public function test_valid_receipt_lifecycle_state_is_accepted(
        string $state,
        bool $confirmed,
        bool $annulled,
    ): void {
        $receiptId = $this->createReceipt($this->userId, self::UUID_ONE, array_merge(
            $this->fixedTimestamps(),
            [
                'estado' => $state,
                'usuario_confirmador_id' => $confirmed ? $this->otherUserId : null,
                'confirmada_en' => $confirmed ? self::CONFIRMED_AT : null,
                'usuario_anulador_id' => $annulled ? $this->userId : null,
                'anulada_en' => $annulled ? self::ANNULLED_AT : null,
                'motivo_anulacion' => $annulled ? 'Recepcion duplicada' : null,
            ],
        ));

        $this->assertSame($state, DB::table('recepciones_mercaderia')->where('id', $receiptId)->value('estado'));
    }

    public static function validLifecycleStates(): array
    {
        return [
            'draft' => ['borrador', false, false],
            'confirmed' => ['confirmada', true, false],
            'annulled' => ['anulada', true, true],
        ];
    }

    public function test_invalid_receipt_state_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE, ['estado' => 'pendiente']);
    }

    #[DataProvider('invalidLifecycleStructures')]
    public function test_invalid_receipt_lifecycle_structure_is_rejected(
        string $state,
        bool $withConfirmingUser,
        bool $withConfirmationTime,
        bool $withAnnulmentUser,
        bool $withAnnulmentTime,
        ?string $reason,
    ): void {
        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE, array_merge(
            $this->fixedTimestamps(),
            [
                'estado' => $state,
                'usuario_confirmador_id' => $withConfirmingUser ? $this->otherUserId : null,
                'confirmada_en' => $withConfirmationTime ? self::CONFIRMED_AT : null,
                'usuario_anulador_id' => $withAnnulmentUser ? $this->userId : null,
                'anulada_en' => $withAnnulmentTime ? self::ANNULLED_AT : null,
                'motivo_anulacion' => $reason,
            ],
        ));
    }

    public static function invalidLifecycleStructures(): array
    {
        return [
            'draft with confirming user' => ['borrador', true, false, false, false, null],
            'draft with confirmation time' => ['borrador', false, true, false, false, null],
            'confirmed without user' => ['confirmada', false, true, false, false, null],
            'confirmed without time' => ['confirmada', true, false, false, false, null],
            'annulled without confirmer' => ['anulada', false, true, true, true, 'Motivo valido'],
            'annulled without confirmation time' => ['anulada', true, false, true, true, 'Motivo valido'],
            'annulled without annulling user' => ['anulada', true, true, false, true, 'Motivo valido'],
            'annulled without annulment time' => ['anulada', true, true, true, false, 'Motivo valido'],
            'annulled without reason' => ['anulada', true, true, true, true, null],
            'annulled with empty reason' => ['anulada', true, true, true, true, ''],
            'annulled with spaces reason' => ['anulada', true, true, true, true, '   '],
            'annulled with padded reason' => ['anulada', true, true, true, true, ' Motivo '],
        ];
    }

    #[DataProvider('invalidLifecycleChronologies')]
    public function test_invalid_receipt_lifecycle_chronology_is_rejected(
        string $confirmedAt,
        string $annulledAt,
    ): void {
        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE, array_merge(
            $this->fixedTimestamps(),
            [
                'estado' => 'anulada',
                'usuario_confirmador_id' => $this->otherUserId,
                'confirmada_en' => $confirmedAt,
                'usuario_anulador_id' => $this->userId,
                'anulada_en' => $annulledAt,
                'motivo_anulacion' => 'Cronologia invalida',
            ],
        ));
    }

    public static function invalidLifecycleChronologies(): array
    {
        return [
            'confirmation before creation' => [self::EARLIER_AT, self::ANNULLED_AT],
            'annulment before confirmation' => [self::CONFIRMED_AT, self::CREATED_AT],
        ];
    }

    public function test_equal_occurrence_and_creation_without_adjustment_reason_is_accepted(): void
    {
        $receiptId = $this->createReceipt($this->userId, self::UUID_ONE, $this->fixedTimestamps());
        $receipt = DB::table('recepciones_mercaderia')->where('id', $receiptId)->first();

        $this->assertSame($receipt->created_at, $receipt->ocurrido_en);
        $this->assertNull($receipt->motivo_ajuste_ocurrido_en);
    }

    public function test_backdated_receipt_with_trimmed_reason_is_accepted(): void
    {
        $receiptId = $this->createReceipt($this->userId, self::UUID_ONE, [
            'ocurrido_en' => self::EARLIER_AT,
            'created_at' => self::CREATED_AT,
            'motivo_ajuste_ocurrido_en' => 'Registro retroactivo autorizado',
        ]);

        $this->assertSame(
            'Registro retroactivo autorizado',
            DB::table('recepciones_mercaderia')->where('id', $receiptId)->value('motivo_ajuste_ocurrido_en'),
        );
    }

    #[DataProvider('invalidOccurrenceStructures')]
    public function test_invalid_receipt_occurrence_structure_is_rejected(
        string $occurredAt,
        ?string $reason,
    ): void {
        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE, [
            'ocurrido_en' => $occurredAt,
            'created_at' => self::CREATED_AT,
            'motivo_ajuste_ocurrido_en' => $reason,
        ]);
    }

    public static function invalidOccurrenceStructures(): array
    {
        return [
            'past without reason' => [self::EARLIER_AT, null],
            'past with empty reason' => [self::EARLIER_AT, ''],
            'past with spaces' => [self::EARLIER_AT, '   '],
            'past with padded reason' => [self::EARLIER_AT, ' Motivo '],
            'future occurrence' => [self::FUTURE_AT, 'Fecha futura'],
            'equal with unnecessary reason' => [self::CREATED_AT, 'Motivo innecesario'],
        ];
    }

    public function test_duplicate_receipt_idempotency_key_is_rejected(): void
    {
        $this->createReceipt($this->userId, self::UUID_ONE);

        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE);
    }

    public function test_distinct_receipt_idempotency_keys_are_accepted(): void
    {
        $this->createReceipt($this->userId, self::UUID_ONE);
        $this->createReceipt($this->userId, self::UUID_TWO);

        $this->assertSame(2, DB::table('recepciones_mercaderia')->count());
    }

    #[DataProvider('invalidObservations')]
    public function test_invalid_receipt_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createReceipt($this->userId, self::UUID_ONE, ['observacion' => $observation]);
    }

    public static function invalidObservations(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'leading space' => [' Observacion'],
            'trailing space' => ['Observacion '],
        ];
    }

    private function fixedTimestamps(): array
    {
        return [
            'ocurrido_en' => self::CREATED_AT,
            'created_at' => self::CREATED_AT,
        ];
    }
}
