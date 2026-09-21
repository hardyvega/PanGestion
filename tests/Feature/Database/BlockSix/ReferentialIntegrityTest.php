<?php

namespace Tests\Feature\Database\BlockSix;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesBlockOneRecords;
use Tests\Support\CreatesBlockSixRecords;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class ReferentialIntegrityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use CreatesBlockSixRecords;
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    private int $roleId;

    private int $cashRegisterId;

    private int $openingUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleId = $this->createRole();
        $this->cashRegisterId = $this->createCashRegister();
        $this->openingUserId = $this->createUser($this->roleId);
    }

    public function test_cash_register_with_session_cannot_be_deleted(): void
    {
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->expectException(QueryException::class);
        DB::table('cajas')->where('id', $this->cashRegisterId)->delete();
    }

    public function test_opening_user_with_session_cannot_be_deleted(): void
    {
        $this->createCashSession($this->cashRegisterId, $this->openingUserId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $this->openingUserId)->delete();
    }

    public function test_closing_user_with_closed_session_cannot_be_deleted(): void
    {
        $closingUserId = $this->createDistinctUser('usuario_cierre');
        $this->createCashSession($this->cashRegisterId, $this->openingUserId, [
            'usuario_cierre_id' => $closingUserId,
            'abierta_en' => '2026-09-21 08:00:00-03',
            'cerrada_en' => '2026-09-21 16:00:00-03',
            'monto_cierre_declarado' => '100.00',
            'monto_teorico_cierre' => '100.00',
        ]);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $closingUserId)->delete();
    }

    public function test_cash_session_with_movement_cannot_be_deleted(): void
    {
        $sessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $this->createCashMovement($sessionId, $this->openingUserId);

        $this->expectException(QueryException::class);
        DB::table('sesiones_caja')->where('id', $sessionId)->delete();
    }

    public function test_user_with_cash_movement_cannot_be_deleted(): void
    {
        $movementUserId = $this->createDistinctUser('usuario_movimiento');
        $sessionId = $this->createCashSession($this->cashRegisterId, $this->openingUserId);
        $this->createCashMovement($sessionId, $movementUserId);

        $this->expectException(QueryException::class);
        DB::table('usuarios')->where('id', $movementUserId)->delete();
    }

    private function createDistinctUser(string $username): int
    {
        return $this->createUser($this->roleId, [
            'nombre' => 'Usuario',
            'apellido' => 'Caja',
            'nombre_usuario' => $username,
        ]);
    }
}
