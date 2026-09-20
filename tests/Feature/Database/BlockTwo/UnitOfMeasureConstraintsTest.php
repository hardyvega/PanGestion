<?php

namespace Tests\Feature\Database\BlockTwo;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockTwoRecords;
use Tests\TestCase;

class UnitOfMeasureConstraintsTest extends TestCase
{
    use CreatesBlockTwoRecords;
    use RefreshDatabase;

    public function test_unit_of_measure_can_be_created_with_database_defaults(): void
    {
        $unitId = $this->createUnitOfMeasure();

        $unit = DB::table('unidades_medida')->where('id', $unitId)->first();

        $this->assertNotNull($unit);
        $this->assertSame('kg', $unit->codigo);
        $this->assertSame('Kilogramo', $unit->nombre);
        $this->assertSame('kg', $unit->simbolo);
        $this->assertSame('masa', $unit->magnitud);
        $this->assertTrue((bool) $unit->activo);
        $this->assertNotNull($unit->created_at);
        $this->assertNotNull($unit->updated_at);
    }

    public function test_identical_valid_unit_code_cannot_be_duplicated(): void
    {
        $this->createUnitOfMeasure();

        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData([
            'nombre' => 'Gramo',
            'simbolo' => 'g',
        ]));
    }

    public function test_unit_name_is_unique_case_insensitively(): void
    {
        $this->createUnitOfMeasure();

        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData([
            'codigo' => 'kilogramo_alterno',
            'nombre' => 'KILOGRAMO',
            'simbolo' => 'kg2',
        ]));
    }

    public function test_unit_symbol_is_unique_case_insensitively(): void
    {
        $this->createUnitOfMeasure();

        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData([
            'codigo' => 'kilogramo_alterno',
            'nombre' => 'Kilogramo alternativo',
            'simbolo' => 'KG',
        ]));
    }

    #[DataProvider('invalidUnitCodeValues')]
    public function test_unit_code_check_constraints_reject_invalid_values(string $codigo): void
    {
        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData(['codigo' => $codigo]));
    }

    public static function invalidUnitCodeValues(): array
    {
        return [
            'empty code' => [''],
            'code with surrounding whitespace' => [' kg'],
            'uppercase code' => ['KG'],
            'invalid code format' => ['1kg'],
        ];
    }

    #[DataProvider('invalidUnitNameValues')]
    public function test_unit_name_check_constraints_reject_invalid_values(string $nombre): void
    {
        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData(['nombre' => $nombre]));
    }

    public static function invalidUnitNameValues(): array
    {
        return [
            'empty name' => [''],
            'name with surrounding whitespace' => [' Kilogramo'],
        ];
    }

    #[DataProvider('invalidUnitSymbolValues')]
    public function test_unit_symbol_check_constraints_reject_invalid_values(string $simbolo): void
    {
        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData(['simbolo' => $simbolo]));
    }

    public static function invalidUnitSymbolValues(): array
    {
        return [
            'empty symbol' => [''],
            'symbol with surrounding whitespace' => [' kg'],
        ];
    }

    public function test_unit_symbol_preserves_visible_capitalization(): void
    {
        $unitId = $this->createUnitOfMeasure([
            'codigo' => 'litro',
            'nombre' => 'Litro',
            'simbolo' => 'L',
            'magnitud' => 'volumen',
        ]);

        $this->assertSame('L', DB::table('unidades_medida')->where('id', $unitId)->value('simbolo'));
    }

    #[DataProvider('approvedMagnitudes')]
    public function test_each_approved_magnitude_is_accepted(string $magnitud, array $uniqueValues): void
    {
        $unitId = $this->createUnitOfMeasure(array_merge($uniqueValues, ['magnitud' => $magnitud]));

        $this->assertSame(
            $magnitud,
            DB::table('unidades_medida')->where('id', $unitId)->value('magnitud'),
        );
    }

    public static function approvedMagnitudes(): array
    {
        return [
            'unidad' => ['unidad', [
                'codigo' => 'unidad_prueba',
                'nombre' => 'Unidad de prueba',
                'simbolo' => 'un-prueba',
            ]],
            'masa' => ['masa', [
                'codigo' => 'masa_prueba',
                'nombre' => 'Masa de prueba',
                'simbolo' => 'kg-prueba',
            ]],
            'volumen' => ['volumen', [
                'codigo' => 'volumen_prueba',
                'nombre' => 'Volumen de prueba',
                'simbolo' => 'L-prueba',
            ]],
            'otra' => ['otra', [
                'codigo' => 'otra_prueba',
                'nombre' => 'Otra medida de prueba',
                'simbolo' => 'ot-prueba',
            ]],
        ];
    }

    public function test_unapproved_magnitude_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('unidades_medida')->insert($this->unitOfMeasureData([
            'magnitud' => 'longitud',
        ]));
    }
}
