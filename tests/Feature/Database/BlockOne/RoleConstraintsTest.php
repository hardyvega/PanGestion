<?php

namespace Tests\Feature\Database\BlockOne;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

class RoleConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    public function test_role_can_be_created_with_database_defaults(): void
    {
        $roleId = $this->createRole();

        $role = DB::table('roles')->where('id', $roleId)->first();

        $this->assertNotNull($role);
        $this->assertSame('administrador', $role->codigo);
        $this->assertSame('Administrador', $role->nombre);
        $this->assertNull($role->descripcion);
        $this->assertTrue((bool) $role->activo);
        $this->assertNotNull($role->created_at);
        $this->assertNotNull($role->updated_at);
    }

    public function test_identical_valid_role_code_cannot_be_duplicated(): void
    {
        $this->createRole();

        $this->expectException(QueryException::class);

        DB::table('roles')->insert($this->roleData([
            'nombre' => 'Administrador alternativo',
        ]));
    }

    #[DataProvider('invalidRoleValues')]
    public function test_role_check_constraints_reject_invalid_values(array $overrides): void
    {
        $this->expectException(QueryException::class);

        DB::table('roles')->insert($this->roleData($overrides));
    }

    public static function invalidRoleValues(): array
    {
        return [
            'empty code' => [['codigo' => '']],
            'code with surrounding whitespace' => [['codigo' => ' administrador']],
            'uppercase code' => [['codigo' => 'Administrador']],
            'invalid code format' => [['codigo' => '1administrador']],
            'empty name' => [['nombre' => '']],
            'name with surrounding whitespace' => [['nombre' => ' Administrador']],
        ];
    }
}
