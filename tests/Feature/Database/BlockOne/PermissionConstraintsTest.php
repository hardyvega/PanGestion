<?php

namespace Tests\Feature\Database\BlockOne;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

class PermissionConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    public function test_permission_can_be_created_with_database_defaults(): void
    {
        $permissionId = $this->createPermission();

        $permission = DB::table('permisos')->where('id', $permissionId)->first();

        $this->assertNotNull($permission);
        $this->assertSame('ventas.crear', $permission->codigo);
        $this->assertSame('Crear ventas', $permission->nombre);
        $this->assertSame('ventas', $permission->modulo);
        $this->assertNull($permission->descripcion);
        $this->assertTrue((bool) $permission->activo);
        $this->assertNotNull($permission->created_at);
        $this->assertNotNull($permission->updated_at);
    }

    public function test_identical_valid_permission_code_cannot_be_duplicated(): void
    {
        $this->createPermission();

        $this->expectException(QueryException::class);

        DB::table('permisos')->insert($this->permissionData([
            'nombre' => 'Crear ventas alternativo',
        ]));
    }

    #[DataProvider('invalidPermissionValues')]
    public function test_permission_check_constraints_reject_invalid_values(array $overrides): void
    {
        $this->expectException(QueryException::class);

        DB::table('permisos')->insert($this->permissionData($overrides));
    }

    public static function invalidPermissionValues(): array
    {
        return [
            'empty code' => [['codigo' => '']],
            'code with surrounding whitespace' => [['codigo' => ' ventas.crear']],
            'uppercase code' => [['codigo' => 'Ventas.crear']],
            'code without module and action' => [['codigo' => 'ventas']],
            'empty name' => [['nombre' => '']],
            'name with surrounding whitespace' => [['nombre' => ' Crear ventas']],
            'empty module' => [['modulo' => '']],
            'module with surrounding whitespace' => [['modulo' => ' ventas']],
            'uppercase module' => [['modulo' => 'Ventas']],
            'invalid module format' => [['modulo' => 'ventas.web']],
        ];
    }
}
