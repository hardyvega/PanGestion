<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EffectivePermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW_RECEIPTS = 'inventario.recepciones.ver';

    private const CREATE_RECEIPTS = 'inventario.recepciones.crear';

    public function test_active_user_role_permission_and_pivot_allow_access(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $context = $this->createAuthorizationContext([$permission]);

        $this->assertTrue($this->resolver()->allows($context['user'], $permission));
    }

    #[DataProvider('inactiveAuthorizationComponents')]
    public function test_each_inactive_authorization_component_denies_access(string $component): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $context = $this->createAuthorizationContext([$permission]);
        $resolver = $this->resolver();

        $this->assertTrue($resolver->allows($context['user'], $permission));

        match ($component) {
            'user' => DB::table('usuarios')->where('id', $context['user']->getKey())->update(['activo' => false]),
            'role' => DB::table('roles')->where('id', $context['role_id'])->update(['activo' => false]),
            'permission' => DB::table('permisos')
                ->where('id', $context['permission_ids'][$permission->value])
                ->update(['activo' => false]),
        };

        $this->assertFalse($resolver->allows($context['user'], $permission));
    }

    public static function inactiveAuthorizationComponents(): array
    {
        return [
            'inactive user' => ['user'],
            'inactive role' => ['role'],
            'inactive permission' => ['permission'],
        ];
    }

    public function test_permission_without_pivot_denies_access(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $roleId = $this->createRole();
        $user = $this->createUser($roleId);
        $this->createPermission($permission);

        $this->assertFalse($this->resolver()->allows($user, $permission));
    }

    public function test_missing_permission_row_denies_access(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $roleId = $this->createRole();
        $user = $this->createUser($roleId);

        $this->assertFalse($this->resolver()->allows($user, $permission));
    }

    public function test_role_name_never_bypasses_the_permission_pivot(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $roleId = $this->createRole('dueno');
        $user = $this->createUser($roleId);
        $this->createPermission($permission);

        $this->assertFalse($this->resolver()->allows($user, $permission));
    }

    public function test_allows_all_requires_every_permission_and_accepts_an_empty_iterable(): void
    {
        $view = PermissionCode::from(self::VIEW_RECEIPTS);
        $create = PermissionCode::from(self::CREATE_RECEIPTS);
        $context = $this->createAuthorizationContext([$view, $create]);
        $resolver = $this->resolver();

        $this->assertTrue($resolver->allowsAll($context['user'], [$view, $create]));
        $this->assertTrue($resolver->allowsAll($context['user'], []));

        DB::table('rol_permiso')
            ->where('rol_id', $context['role_id'])
            ->where('permiso_id', $context['permission_ids'][$create->value])
            ->delete();

        $this->assertFalse($resolver->allowsAll($context['user'], [$view, $create]));
    }

    public function test_authorize_variants_return_normally_or_throw_authorization_exception(): void
    {
        $view = PermissionCode::from(self::VIEW_RECEIPTS);
        $create = PermissionCode::from(self::CREATE_RECEIPTS);
        $context = $this->createAuthorizationContext([$view, $create]);
        $resolver = $this->resolver();

        $resolver->authorize($context['user'], $view);
        $resolver->authorizeAll($context['user'], [$view, $create]);
        $this->addToAssertionCount(2);

        DB::table('rol_permiso')
            ->where('rol_id', $context['role_id'])
            ->where('permiso_id', $context['permission_ids'][$create->value])
            ->delete();

        $this->assertAuthorizationDenied(
            fn () => $resolver->authorize($context['user'], $create),
        );
        $this->assertAuthorizationDenied(
            fn () => $resolver->authorizeAll($context['user'], [$view, $create]),
        );
    }

    public function test_each_authorization_reads_current_user_role_and_permission_state(): void
    {
        $permission = PermissionCode::from(self::VIEW_RECEIPTS);
        $context = $this->createAuthorizationContext([$permission]);
        $resolver = $this->resolver();
        $userId = $context['user']->getKey();
        $permissionId = $context['permission_ids'][$permission->value];

        $this->assertTrue($resolver->allows($context['user'], $permission));

        DB::table('usuarios')->where('id', $userId)->update(['activo' => false]);
        $this->assertFalse($resolver->allows($context['user'], $permission));
        DB::table('usuarios')->where('id', $userId)->update(['activo' => true]);

        DB::table('roles')->where('id', $context['role_id'])->update(['activo' => false]);
        $this->assertFalse($resolver->allows($context['user'], $permission));
        DB::table('roles')->where('id', $context['role_id'])->update(['activo' => true]);

        DB::table('permisos')->where('id', $permissionId)->update(['activo' => false]);
        $this->assertFalse($resolver->allows($context['user'], $permission));
        DB::table('permisos')->where('id', $permissionId)->update(['activo' => true]);

        $this->assertTrue($resolver->allows($context['user'], $permission));
    }

    public function test_allows_all_resolves_a_permission_batch_with_one_query(): void
    {
        $view = PermissionCode::from(self::VIEW_RECEIPTS);
        $create = PermissionCode::from(self::CREATE_RECEIPTS);
        $context = $this->createAuthorizationContext([$view, $create]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $allowed = $this->resolver()->allowsAll($context['user'], [$view, $create]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($allowed);
        $this->assertCount(1, $queries);
    }

    private function resolver(): EffectivePermissionResolver
    {
        return $this->app->make(EffectivePermissionResolver::class);
    }

    private function createAuthorizationContext(array $permissions, string $roleCode = 'operador'): array
    {
        $roleId = $this->createRole($roleCode);
        $user = $this->createUser($roleId);
        $permissionIds = [];

        foreach ($permissions as $permission) {
            $permissionId = $this->createPermission($permission);
            $permissionIds[$permission->value] = $permissionId;
            DB::table('rol_permiso')->insert([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
            ]);
        }

        return [
            'user' => $user,
            'role_id' => $roleId,
            'permission_ids' => $permissionIds,
        ];
    }

    private function createRole(string $code = 'operador'): int
    {
        return (int) DB::table('roles')->insertGetId([
            'codigo' => $code,
            'nombre' => ucfirst($code),
            'descripcion' => null,
            'activo' => true,
        ]);
    }

    private function createUser(int $roleId): User
    {
        $id = (int) DB::table('usuarios')->insertGetId([
            'rol_id' => $roleId,
            'nombre' => 'Usuario',
            'apellido' => 'Prueba',
            'nombre_usuario' => 'usuario_permisos',
            'correo' => 'permisos@example.test',
            'password' => Hash::make('clave-de-prueba'),
            'activo' => true,
            'ultimo_acceso' => null,
            'remember_token' => null,
        ]);

        return User::query()->findOrFail($id);
    }

    private function createPermission(PermissionCode $permission): int
    {
        return (int) DB::table('permisos')->insertGetId([
            'codigo' => $permission->value,
            'nombre' => 'Permiso '.$permission->value,
            'modulo' => explode('.', $permission->value, 2)[0],
            'descripcion' => 'Descripcion de '.$permission->value,
            'activo' => true,
        ]);
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected authorization to be denied.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
