<?php

namespace Tests\Feature\Database\BlockSeven;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSevenRecords;
use Tests\TestCase;

class OrderTransitionConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use CreatesBlockOneRecords;
    use CreatesBlockSevenRecords;
    use RefreshDatabase;

    private int $orderId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->createRole());
        $this->orderId = $this->createOrder($this->createClient(), $this->userId);
    }

    public function test_transition_columns_match_approved_shape_without_updated_at(): void
    {
        $columns = DB::select(<<<'SQL'
            SELECT column_name, data_type, is_nullable, character_maximum_length
              FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'transiciones_pedido'
             ORDER BY ordinal_position
            SQL);

        $actual = [];
        foreach ($columns as $column) {
            $actual[$column->column_name] = [
                'type' => $column->data_type,
                'nullable' => $column->is_nullable === 'YES',
                'length' => $column->character_maximum_length === null
                    ? null
                    : (int) $column->character_maximum_length,
            ];
        }

        $this->assertSame([
            'id' => ['type' => 'bigint', 'nullable' => false, 'length' => null],
            'pedido_id' => ['type' => 'bigint', 'nullable' => false, 'length' => null],
            'estado_anterior' => ['type' => 'character varying', 'nullable' => true, 'length' => 20],
            'estado_nuevo' => ['type' => 'character varying', 'nullable' => false, 'length' => 20],
            'usuario_id' => ['type' => 'bigint', 'nullable' => false, 'length' => null],
            'clave_idempotencia' => ['type' => 'uuid', 'nullable' => false, 'length' => null],
            'motivo' => ['type' => 'text', 'nullable' => true, 'length' => null],
            'ocurrida_en' => ['type' => 'timestamp with time zone', 'nullable' => false, 'length' => null],
            'created_at' => ['type' => 'timestamp with time zone', 'nullable' => false, 'length' => null],
        ], $actual);
    }

    #[DataProvider('validTransitions')]
    public function test_valid_order_transition_is_accepted(?string $previous, string $next, ?string $reason): void
    {
        $id = $this->createTransition(
            $previous,
            $next,
            $reason === null ? [] : ['motivo' => $reason],
        );
        $transition = DB::table('transiciones_pedido')->where('id', $id)->first();

        $this->assertNotNull($transition);
        $this->assertSame($previous, $transition->estado_anterior);
        $this->assertSame($next, $transition->estado_nuevo);
    }

    public static function validTransitions(): array
    {
        return [
            'initial' => [null, 'pendiente', null],
            'pending to confirmed' => ['pendiente', 'confirmado', null],
            'confirmed to preparation' => ['confirmado', 'en_preparacion', null],
            'preparation to ready' => ['en_preparacion', 'listo', null],
            'ready to delivered' => ['listo', 'entregado', null],
            'pending cancellation' => ['pendiente', 'cancelado', 'Cancelación autorizada'],
            'confirmed cancellation' => ['confirmado', 'cancelado', 'Cancelación autorizada'],
            'preparation cancellation' => ['en_preparacion', 'cancelado', 'Cancelación autorizada'],
            'ready cancellation' => ['listo', 'cancelado', 'Cancelación autorizada'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_order_transition_is_rejected(?string $previous, string $next): void
    {
        $this->expectException(QueryException::class);
        $this->createTransition($previous, $next);
    }

    public static function invalidTransitions(): array
    {
        return [
            'self pending' => ['pendiente', 'pendiente'],
            'skip pending to ready' => ['pendiente', 'listo'],
            'skip confirmed to delivered' => ['confirmado', 'entregado'],
            'delivered to cancelled' => ['entregado', 'cancelado'],
            'cancelled to pending' => ['cancelado', 'pendiente'],
            'initial to confirmed' => [null, 'confirmado'],
        ];
    }

    #[DataProvider('invalidCancellationReasons')]
    public function test_cancellation_requires_trimmed_nonblank_reason(?string $reason): void
    {
        $this->expectException(QueryException::class);
        $this->createTransition('pendiente', 'cancelado', ['motivo' => $reason]);
    }

    public static function invalidCancellationReasons(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Motivo'],
            'trailing space' => ['Motivo '],
        ];
    }

    public function test_normal_transition_can_omit_reason(): void
    {
        $id = $this->createTransition('pendiente', 'confirmado');

        $this->assertNull(DB::table('transiciones_pedido')->where('id', $id)->value('motivo'));
    }

    public function test_transition_rejects_occurred_timestamp_after_creation(): void
    {
        $this->expectException(QueryException::class);
        $this->createTransition('pendiente', 'confirmado', [
            'ocurrida_en' => '2026-09-25 12:00:00+00',
            'created_at' => '2026-09-25 11:59:59+00',
        ]);
    }

    public function test_transition_idempotency_key_is_unique(): void
    {
        $key = '11111111-1111-4111-8111-111111111111';
        $this->createTransition(null, 'pendiente', ['clave_idempotencia' => $key]);

        $this->expectException(QueryException::class);
        $this->createTransition('pendiente', 'confirmado', ['clave_idempotencia' => $key]);
    }

    public function test_transition_has_no_updated_at_column(): void
    {
        $id = $this->createTransition(null, 'pendiente');
        $transition = DB::table('transiciones_pedido')->where('id', $id)->first();

        $this->assertFalse(property_exists($transition, 'updated_at'));
    }

    public function test_order_referenced_by_transition_cannot_be_deleted(): void
    {
        $this->createTransition(null, 'pendiente');

        $this->expectException(QueryException::class);
        DB::table('pedidos')->where('id', $this->orderId)->delete();
    }

    public function test_user_referenced_by_transition_cannot_be_deleted(): void
    {
        $this->createTransition(null, 'pendiente');

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $this->userId)->delete();
    }

    private function createTransition(?string $previous, string $next, array $overrides = []): int
    {
        return (int) DB::table('transiciones_pedido')->insertGetId(array_merge([
            'pedido_id' => $this->orderId,
            'estado_anterior' => $previous,
            'estado_nuevo' => $next,
            'usuario_id' => $this->userId,
            'clave_idempotencia' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ], $overrides));
    }
}
