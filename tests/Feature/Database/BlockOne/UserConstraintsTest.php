<?php

namespace Tests\Feature\Database\BlockOne;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

class UserConstraintsTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    public function test_user_can_be_created_with_an_existing_role_and_defaults(): void
    {
        $roleId = $this->createRole();
        $userId = $this->createUser($roleId, [
            'nombre_usuario' => 'CajaPrincipal',
        ]);

        $user = DB::table('usuarios')->where('id', $userId)->first();

        $this->assertNotNull($user);
        $this->assertSame($roleId, (int) $user->rol_id);
        $this->assertSame('CajaPrincipal', $user->nombre_usuario);
        $this->assertNull($user->correo);
        $this->assertTrue((bool) $user->activo);
        $this->assertNull($user->ultimo_acceso);
        $this->assertNull($user->remember_token);
        $this->assertNotNull($user->created_at);
        $this->assertNotNull($user->updated_at);
    }

    public function test_user_with_nonexistent_role_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('usuarios')->insert($this->userData(999999));
    }

    public function test_username_is_unique_case_insensitively(): void
    {
        $roleId = $this->createRole();
        $this->createUser($roleId, [
            'nombre_usuario' => 'CajaPrincipal',
        ]);

        $this->expectException(QueryException::class);

        DB::table('usuarios')->insert($this->userData($roleId, [
            'nombre_usuario' => 'cajaprincipal',
        ]));
    }

    public function test_email_is_unique_case_insensitively(): void
    {
        $roleId = $this->createRole();
        $this->createUser($roleId, [
            'nombre_usuario' => 'cajero_uno',
            'correo' => 'Cliente@Ejemplo.cl',
        ]);

        $this->expectException(QueryException::class);

        DB::table('usuarios')->insert($this->userData($roleId, [
            'nombre_usuario' => 'cajero_dos',
            'correo' => 'cliente@ejemplo.cl',
        ]));
    }

    public function test_multiple_users_can_have_null_email(): void
    {
        $roleId = $this->createRole();
        $this->createUser($roleId, [
            'nombre_usuario' => 'cajero_uno',
        ]);
        $this->createUser($roleId, [
            'nombre_usuario' => 'cajero_dos',
        ]);

        $this->assertSame(2, DB::table('usuarios')->whereNull('correo')->count());
    }

    #[DataProvider('invalidUserValues')]
    public function test_user_check_constraints_reject_invalid_values(array $overrides): void
    {
        $roleId = $this->createRole();

        $this->expectException(QueryException::class);

        DB::table('usuarios')->insert($this->userData($roleId, $overrides));
    }

    public static function invalidUserValues(): array
    {
        return [
            'empty name' => [['nombre' => '']],
            'name with surrounding whitespace' => [['nombre' => ' Hardy']],
            'empty surname' => [['apellido' => '']],
            'surname with surrounding whitespace' => [['apellido' => ' Vega']],
            'empty username' => [['nombre_usuario' => '']],
            'username with surrounding whitespace' => [['nombre_usuario' => ' cajero']],
            'invalid username format' => [['nombre_usuario' => '.cajero']],
            'empty email' => [['correo' => '']],
            'email with surrounding whitespace' => [['correo' => ' cliente@example.com']],
        ];
    }
}
