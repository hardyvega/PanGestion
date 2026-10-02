<?php

namespace Tests\Feature\Database\BlockTen;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockTenRecords;
use Tests\TestCase;

class InventoryCountConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockTenRecords;
    use RefreshDatabase;

    private const CREATED_AT = '2026-09-20 09:00:00-03';

    private const CONFIRMED_AT = '2026-09-20 10:00:00-03';

    private int $roleId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->userId = $this->createUser($this->roleId, ['nombre_usuario' => 'conteo_base']);
    }

    public function test_inventory_count_can_be_created_as_draft_with_database_defaults(): void
    {
        $countId = $this->createInventoryCount($this->userId, $this->userId);
        $count = DB::table('conteos_inventario')->where('id', $countId)->first();

        $this->assertNotNull($count);
        $this->assertNull($count->sesion_caja_id);
        $this->assertSame($this->userId, $count->usuario_creador_id);
        $this->assertSame($this->userId, $count->usuario_responsable_id);
        $this->assertNull($count->usuario_confirmador_id);
        $this->assertSame('borrador', $count->estado);
        $this->assertNull($count->confirmada_en);
        $this->assertNull($count->observacion);
        $this->assertNotNull($count->created_at);
        $this->assertNotNull($count->updated_at);
    }

    public function test_confirmed_inventory_count_with_complete_audit_is_accepted(): void
    {
        $confirmingUserId = $this->createDistinctUser('conteo_confirmador');
        $countId = $this->createInventoryCount($this->userId, $this->userId, [
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $confirmingUserId,
            'confirmada_en' => self::CONFIRMED_AT,
            'created_at' => self::CREATED_AT,
        ]);
        $count = DB::table('conteos_inventario')->where('id', $countId)->first();

        $this->assertSame('confirmado', $count->estado);
        $this->assertSame($confirmingUserId, $count->usuario_confirmador_id);
        $this->assertNotNull($count->confirmada_en);
    }

    #[DataProvider('invalidInventoryCountStates')]
    public function test_invalid_inventory_count_state_is_rejected(string $state): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCount($this->userId, $this->userId, ['estado' => $state]);
    }

    public static function invalidInventoryCountStates(): array
    {
        return [
            'uppercase draft' => ['BORRADOR'],
            'uppercase confirmed' => ['CONFIRMADO'],
            'pending' => ['pendiente'],
            'annulled' => ['anulado'],
            'empty' => [''],
            'other' => ['otro'],
        ];
    }

    #[DataProvider('invalidInventoryCountAuditStructures')]
    public function test_invalid_inventory_count_audit_structure_is_rejected(
        string $state,
        bool $withConfirmingUser,
        ?string $confirmedAt,
    ): void {
        $overrides = [
            'estado' => $state,
            'usuario_confirmador_id' => $withConfirmingUser
                ? $this->createDistinctUser('conteo_auditoria')
                : null,
            'confirmada_en' => $confirmedAt,
            'created_at' => self::CREATED_AT,
        ];

        $this->expectException(QueryException::class);
        $this->createInventoryCount($this->userId, $this->userId, $overrides);
    }

    public static function invalidInventoryCountAuditStructures(): array
    {
        return [
            'draft with confirming user' => ['borrador', true, null],
            'draft with confirmation timestamp' => ['borrador', false, self::CONFIRMED_AT],
            'confirmed without confirming user' => ['confirmado', false, self::CONFIRMED_AT],
            'confirmed without confirmation timestamp' => ['confirmado', true, null],
        ];
    }

    #[DataProvider('validInventoryCountConfirmationDates')]
    public function test_valid_inventory_count_confirmation_date_is_accepted(string $confirmedAt): void
    {
        $countId = $this->createInventoryCount($this->userId, $this->userId, [
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => $confirmedAt,
            'created_at' => self::CREATED_AT,
        ]);

        $this->assertNotNull(DB::table('conteos_inventario')->where('id', $countId)->value('confirmada_en'));
    }

    public static function validInventoryCountConfirmationDates(): array
    {
        return [
            'equals creation' => [self::CREATED_AT],
            'after creation' => [self::CONFIRMED_AT],
        ];
    }

    public function test_inventory_count_confirmation_before_creation_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCount($this->userId, $this->userId, [
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => '2026-09-20 08:59:59-03',
            'created_at' => self::CREATED_AT,
        ]);
    }

    #[DataProvider('validInventoryCountObservations')]
    public function test_valid_inventory_count_observation_is_preserved(?string $observation): void
    {
        $countId = $this->createInventoryCount(
            $this->userId,
            $this->userId,
            ['observacion' => $observation],
        );

        $this->assertSame(
            $observation,
            DB::table('conteos_inventario')->where('id', $countId)->value('observacion'),
        );
    }

    public static function validInventoryCountObservations(): array
    {
        return [
            'null' => [null],
            'trimmed text' => ['Conteo de cierre'],
        ];
    }

    #[DataProvider('invalidInventoryCountObservations')]
    public function test_invalid_inventory_count_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createInventoryCount(
            $this->userId,
            $this->userId,
            ['observacion' => $observation],
        );
    }

    public static function invalidInventoryCountObservations(): array
    {
        return [
            'empty' => [''],
            'spaces only' => ['   '],
            'leading space' => [' Conteo'],
            'trailing space' => ['Conteo '],
        ];
    }

    public function test_second_simultaneous_draft_inventory_count_is_rejected_globally(): void
    {
        $this->createInventoryCount($this->userId, $this->userId);

        $this->expectException(QueryException::class);
        $this->createInventoryCount($this->userId, $this->userId);
    }

    public function test_one_draft_and_multiple_confirmed_inventory_counts_are_accepted(): void
    {
        $this->createInventoryCount($this->userId, $this->userId);
        $this->createInventoryCount($this->userId, $this->userId, [
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => self::CONFIRMED_AT,
            'created_at' => self::CREATED_AT,
        ]);
        $this->createInventoryCount($this->userId, $this->userId, [
            'estado' => 'confirmado',
            'usuario_confirmador_id' => $this->userId,
            'confirmada_en' => '2026-09-20 12:00:00-03',
            'created_at' => '2026-09-20 11:00:00-03',
        ]);

        $this->assertSame(1, DB::table('conteos_inventario')->where('estado', 'borrador')->count());
        $this->assertSame(2, DB::table('conteos_inventario')->where('estado', 'confirmado')->count());
    }

    public function test_inventory_count_accepts_null_cash_session(): void
    {
        $countId = $this->createInventoryCount(
            $this->userId,
            $this->userId,
            ['sesion_caja_id' => null],
        );

        $this->assertNull(DB::table('conteos_inventario')->where('id', $countId)->value('sesion_caja_id'));
    }

    public function test_same_user_can_create_and_be_responsible_for_inventory_count(): void
    {
        $countId = $this->createInventoryCount($this->userId, $this->userId);
        $count = DB::table('conteos_inventario')->where('id', $countId)->first();

        $this->assertSame($count->usuario_creador_id, $count->usuario_responsable_id);
        $this->assertNull($count->usuario_confirmador_id);
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Conteo',
            'nombre_usuario' => $username,
        ]);
    }
}
