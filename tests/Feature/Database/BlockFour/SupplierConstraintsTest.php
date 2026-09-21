<?php

namespace Tests\Feature\Database\BlockFour;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\TestCase;

class SupplierConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use RefreshDatabase;

    public function test_supplier_can_be_created_with_minimum_data_and_defaults(): void
    {
        $id = $this->createSupplier();
        $supplier = DB::table('proveedores')->where('id', $id)->first();

        $this->assertNotNull($supplier);
        $this->assertSame($id, $supplier->id);
        $this->assertSame('proveedor_prueba', $supplier->codigo);
        $this->assertSame('Proveedor de prueba', $supplier->nombre);
        $this->assertNull($supplier->nombre_contacto);
        $this->assertNull($supplier->telefono);
        $this->assertNull($supplier->correo);
        $this->assertNull($supplier->observacion);
        $this->assertTrue($supplier->activo);
        $this->assertNotNull($supplier->created_at);
        $this->assertNotNull($supplier->updated_at);
    }

    #[DataProvider('validSupplierCodes')]
    public function test_valid_supplier_code_is_preserved(string $code): void
    {
        $id = $this->createSupplier(['codigo' => $code]);

        $this->assertSame($code, DB::table('proveedores')->where('id', $id)->value('codigo'));
    }

    public static function validSupplierCodes(): array
    {
        return [
            'alphabetic start' => ['proveedor1'],
            'numeric start' => ['1proveedor'],
            'hyphen' => ['proveedor-uno'],
            'underscore' => ['proveedor_uno'],
        ];
    }

    public function test_duplicate_supplier_code_is_rejected(): void
    {
        $this->createSupplier();

        $this->expectException(QueryException::class);
        $this->createSupplier(['nombre' => 'Otro proveedor']);
    }

    #[DataProvider('invalidSupplierCodes')]
    public function test_invalid_supplier_code_is_rejected(string $code): void
    {
        $this->expectException(QueryException::class);
        $this->createSupplier(['codigo' => $code]);
    }

    public static function invalidSupplierCodes(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' proveedor'],
            'trailing space' => ['proveedor '],
            'uppercase' => ['Proveedor'],
            'unapproved character' => ['proveedor.1'],
        ];
    }

    public function test_duplicate_supplier_names_are_allowed(): void
    {
        $firstId = $this->createSupplier();
        $secondId = $this->createSupplier(['codigo' => 'proveedor_alterno']);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('proveedores')->where('nombre', 'Proveedor de prueba')->count());
    }

    #[DataProvider('invalidSupplierNames')]
    public function test_invalid_supplier_name_is_rejected(string $name): void
    {
        $this->expectException(QueryException::class);
        $this->createSupplier(['nombre' => $name]);
    }

    public static function invalidSupplierNames(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Proveedor'],
            'trailing space' => ['Proveedor '],
        ];
    }

    #[DataProvider('validOptionalSupplierFields')]
    public function test_optional_supplier_field_accepts_valid_value(string $field, string $value): void
    {
        $id = $this->createSupplier([$field => $value]);

        $this->assertSame($value, DB::table('proveedores')->where('id', $id)->value($field));
    }

    public static function validOptionalSupplierFields(): array
    {
        return [
            'contact name' => ['nombre_contacto', 'Ana Perez'],
            'phone' => ['telefono', '+56 9 1234 5678'],
            'email' => ['correo', 'compras@example.cl'],
            'observation' => ['observacion', 'Despacho durante la manana'],
        ];
    }

    #[DataProvider('invalidOptionalSupplierFields')]
    public function test_invalid_optional_supplier_field_is_rejected(string $field, string $value): void
    {
        $this->expectException(QueryException::class);
        $this->createSupplier([$field => $value]);
    }

    public static function invalidOptionalSupplierFields(): array
    {
        return [
            'contact empty' => ['nombre_contacto', ''],
            'contact leading space' => ['nombre_contacto', ' Ana Perez'],
            'contact trailing space' => ['nombre_contacto', 'Ana Perez '],
            'phone empty' => ['telefono', ''],
            'phone leading space' => ['telefono', ' +56 9 1234 5678'],
            'phone trailing space' => ['telefono', '+56 9 1234 5678 '],
            'email empty' => ['correo', ''],
            'email leading space' => ['correo', ' compras@example.cl'],
            'email trailing space' => ['correo', 'compras@example.cl '],
            'observation empty' => ['observacion', ''],
            'observation leading space' => ['observacion', ' Despacho'],
            'observation trailing space' => ['observacion', 'Despacho '],
        ];
    }
}
