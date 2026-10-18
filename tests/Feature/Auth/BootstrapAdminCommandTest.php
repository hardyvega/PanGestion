<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_the_first_active_admin_with_all_active_permissions(): void
    {
        $this->seedPermissions();

        $this->bootstrapCommand()
            ->assertExitCode(Command::SUCCESS)
            ->expectsOutputToContain('Administrador inicial creado correctamente.');

        $user = User::query()->firstOrFail();
        $role = DB::table('roles')->where('id', $user->rol_id)->first();

        $this->assertSame('Ana', $user->nombre);
        $this->assertSame('Gonzalez', $user->apellido);
        $this->assertSame('admin', $user->nombre_usuario);
        $this->assertTrue($user->activo);
        $this->assertTrue(Hash::check('Admin1234', $user->password));
        $this->assertNotSame('Admin1234', $user->password);
        $this->assertSame('administrador', $role->codigo);
        $this->assertSame('Administrador', $role->nombre);
        $this->assertTrue($role->activo);

        $activePermissionIds = DB::table('permisos')
            ->where('activo', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $assignedPermissionIds = DB::table('rol_permiso')
            ->where('rol_id', $user->rol_id)
            ->orderBy('permiso_id')
            ->pluck('permiso_id')
            ->all();

        $this->assertSame($activePermissionIds, $assignedPermissionIds);
        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::SalesCreate->value));
        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::ArticlesView->value));
        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::ArticlesManage->value));
    }

    public function test_bootstrap_does_not_assign_inactive_permissions(): void
    {
        $this->seedPermissions();
        $inactiveId = (int) DB::table('permisos')->insertGetId([
            'codigo' => 'externo.inactivo',
            'nombre' => 'Permiso externo inactivo',
            'modulo' => 'externo',
            'descripcion' => null,
            'activo' => false,
        ]);

        $this->bootstrapCommand()->assertExitCode(Command::SUCCESS);

        $roleId = (int) DB::table('roles')->value('id');
        $this->assertDatabaseMissing('rol_permiso', [
            'rol_id' => $roleId,
            'permiso_id' => $inactiveId,
        ]);
    }

    public function test_second_bootstrap_is_rejected_without_modifying_existing_state(): void
    {
        $this->seedPermissions();
        $this->bootstrapCommand()->assertExitCode(Command::SUCCESS);

        $this->artisan('pangestion:bootstrap-admin')
            ->expectsOutput('El bootstrap inicial ya fue realizado.')
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(1, DB::table('usuarios')->count());
        $this->assertSame(1, DB::table('roles')->count());
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_policy_is_rejected_without_creating_a_user(string $password): void
    {
        $this->artisan('pangestion:bootstrap-admin')
            ->expectsQuestion('Nombre', 'Ana')
            ->expectsQuestion('Apellido', 'Gonzalez')
            ->expectsQuestion('Nombre de usuario', 'admin')
            ->expectsQuestion('Correo (opcional)', '')
            ->expectsQuestion('Contraseña', $password)
            ->expectsQuestion('Confirmación de contraseña', $password)
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('usuarios', 0);
        $this->assertDatabaseCount('roles', 0);
    }

    public static function invalidPasswords(): array
    {
        return [
            'too short' => ['Adm1234'],
            'without lowercase' => ['ADMIN1234'],
            'without uppercase' => ['admin1234'],
            'without number' => ['Administrador'],
        ];
    }

    public function test_password_without_symbol_is_accepted(): void
    {
        $this->seedPermissions();

        $this->bootstrapCommand()
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_transaction_rolls_back_role_changes_when_bootstrap_configuration_is_invalid(): void
    {
        $roleId = (int) DB::table('roles')->insertGetId([
            'codigo' => 'administrador',
            'nombre' => 'Administrador',
            'descripcion' => null,
            'activo' => false,
        ]);

        $this->bootstrapCommand()
            ->expectsOutput('No existen permisos activos para asignar al administrador inicial.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('usuarios', 0);
        $this->assertDatabaseCount('rol_permiso', 0);
        $this->assertDatabaseHas('roles', [
            'id' => $roleId,
            'activo' => false,
        ]);
    }

    public function test_role_name_is_not_used_as_authorization_bypass(): void
    {
        $this->seedPermissions();
        $this->bootstrapCommand()->assertExitCode(Command::SUCCESS);

        $user = User::query()->firstOrFail();
        DB::table('roles')->where('id', $user->rol_id)->update(['nombre' => 'Nombre cambiado']);

        $this->assertTrue(Gate::forUser($user)->allows(PermissionCode::SalesCreate->value));
    }

    private function bootstrapCommand(): mixed
    {
        return $this->artisan('pangestion:bootstrap-admin')
            ->expectsQuestion('Nombre', 'Ana')
            ->expectsQuestion('Apellido', 'Gonzalez')
            ->expectsQuestion('Nombre de usuario', 'admin')
            ->expectsQuestion('Correo (opcional)', '')
            ->expectsQuestion('Contraseña', 'Admin1234')
            ->expectsQuestion('Confirmación de contraseña', 'Admin1234');
    }

    private function seedPermissions(): void
    {
        foreach (PermissionCode::cases() as $permission) {
            DB::table('permisos')->insert([
                'codigo' => $permission->value,
                'nombre' => $permission->label(),
                'modulo' => $permission->module(),
                'descripcion' => $permission->description(),
                'activo' => true,
            ]);
        }
    }
}
