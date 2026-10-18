<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Rol;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_maps_the_authenticatable_identity_to_usuarios(): void
    {
        $user = new User();

        $this->assertSame('usuarios', $user->getTable());
        $this->assertSame('id', $user->getKeyName());
        $this->assertTrue($user->getIncrementing());
        $this->assertSame('int', $user->getKeyType());
        $this->assertTrue($user->usesTimestamps());
    }

    public function test_web_guard_keeps_the_eloquent_user_provider(): void
    {
        $this->assertSame('web', config('auth.defaults.guard'));
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame('users', config('auth.guards.web.provider'));
        $this->assertSame('eloquent', config('auth.providers.users.driver'));
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }

    public function test_user_is_persisted_and_retrieved_from_usuarios(): void
    {
        $roleId = $this->createRole();
        $userId = $this->createUser($roleId);

        $user = User::query()->findOrFail($userId);

        $this->assertSame($userId, $user->getKey());
        $this->assertSame('operador_prueba', $user->nombre_usuario);
        $this->assertSame('usuarios', $user->getTable());
    }

    public function test_password_uses_the_auth_column_hashed_cast_and_hidden_serialization(): void
    {
        $user = new User();
        $user->password = 'clave-temporal-segura';

        $this->assertSame('password', $user->getAuthPasswordName());
        $this->assertSame('hashed', $user->getCasts()['password']);
        $this->assertTrue(Hash::check('clave-temporal-segura', $user->password));
        $this->assertContains('password', $user->getHidden());
        $this->assertContains('remember_token', $user->getHidden());
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_user_casts_active_and_last_access_without_changing_eloquent_dates(): void
    {
        $roleId = $this->createRole();
        $lastAccess = CarbonImmutable::parse('2026-09-20 15:30:00', 'UTC');
        $userId = $this->createUser($roleId, [
            'activo' => true,
            'ultimo_acceso' => $lastAccess,
        ]);

        $user = User::query()->findOrFail($userId);

        $this->assertTrue($user->activo);
        $this->assertInstanceOf(CarbonImmutable::class, $user->ultimo_acceso);
        $this->assertTrue($lastAccess->equalTo($user->ultimo_acceso));
        $this->assertInstanceOf(CarbonInterface::class, $user->created_at);
        $this->assertInstanceOf(CarbonInterface::class, $user->updated_at);
    }

    public function test_user_belongs_to_role_through_rol_id(): void
    {
        $relation = (new User())->rol();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(Rol::class, $relation->getRelated());
        $this->assertSame('roles', $relation->getRelated()->getTable());
        $this->assertSame('rol_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getOwnerKeyName());
    }

    public function test_sensitive_user_attributes_are_not_mass_assignable(): void
    {
        $user = new User();

        $this->assertFalse($user->isFillable('rol_id'));
        $this->assertFalse($user->isFillable('activo'));
        $this->assertFalse($user->isFillable('password'));
    }

    private function createRole(): int
    {
        return (int) DB::table('roles')->insertGetId([
            'codigo' => 'operador_prueba',
            'nombre' => 'Operador de prueba',
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUser(int $roleId, array $overrides = []): int
    {
        return (int) DB::table('usuarios')->insertGetId(array_merge([
            'rol_id' => $roleId,
            'nombre' => 'Usuario',
            'apellido' => 'Prueba',
            'nombre_usuario' => 'operador_prueba',
            'correo' => 'operador@example.test',
            'password' => Hash::make('clave-de-prueba'),
            'activo' => true,
            'ultimo_acceso' => null,
            'remember_token' => null,
        ], $overrides));
    }
}
