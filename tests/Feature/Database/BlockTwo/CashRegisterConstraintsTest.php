<?php

namespace Tests\Feature\Database\BlockTwo;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class CashRegisterConstraintsTest extends TestCase
{
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_cash_register_can_be_created_with_database_defaults(): void
    {
        $cashRegisterId = $this->createCashRegister(['descripcion' => null]);

        $cashRegister = DB::table('cajas')->where('id', $cashRegisterId)->first();

        $this->assertNotNull($cashRegister);
        $this->assertSame('caja_principal', $cashRegister->codigo);
        $this->assertSame('Caja principal', $cashRegister->nombre);
        $this->assertNull($cashRegister->descripcion);
        $this->assertTrue((bool) $cashRegister->activo);
        $this->assertNotNull($cashRegister->created_at);
        $this->assertNotNull($cashRegister->updated_at);
    }

    public function test_identical_valid_cash_register_code_cannot_be_duplicated(): void
    {
        $this->createCashRegister();

        $this->expectException(QueryException::class);

        DB::table('cajas')->insert($this->cashRegisterData([
            'nombre' => 'Caja secundaria',
        ]));
    }

    public function test_cash_register_name_is_unique_case_insensitively(): void
    {
        $this->createCashRegister();

        $this->expectException(QueryException::class);

        DB::table('cajas')->insert($this->cashRegisterData([
            'codigo' => 'caja_secundaria',
            'nombre' => 'CAJA PRINCIPAL',
        ]));
    }

    #[DataProvider('invalidCashRegisterCodeValues')]
    public function test_cash_register_code_checks_reject_invalid_values(string $codigo): void
    {
        $this->expectException(QueryException::class);

        DB::table('cajas')->insert($this->cashRegisterData(['codigo' => $codigo]));
    }

    public static function invalidCashRegisterCodeValues(): array
    {
        return [
            'empty code' => [''],
            'code with surrounding whitespace' => [' caja_principal'],
            'uppercase code' => ['Caja_principal'],
            'invalid code format' => ['1caja_principal'],
        ];
    }

    #[DataProvider('invalidCashRegisterNameValues')]
    public function test_cash_register_name_checks_reject_invalid_values(string $nombre): void
    {
        $this->expectException(QueryException::class);

        DB::table('cajas')->insert($this->cashRegisterData(['nombre' => $nombre]));
    }

    public static function invalidCashRegisterNameValues(): array
    {
        return [
            'empty name' => [''],
            'name with surrounding whitespace' => [' Caja principal'],
        ];
    }
}
