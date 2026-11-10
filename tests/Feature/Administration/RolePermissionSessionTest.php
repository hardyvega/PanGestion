<?php

declare(strict_types=1);

namespace Tests\Feature\Administration;

use App\Enums\PermissionCode;
use App\Models\Rol;
use App\Models\User;
use App\Services\Administration\OperationalAdminGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

final class RolePermissionSessionTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    private User $actor;
    private Rol $role;
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        config(['session.lifetime' => 1]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->bind(PreventRequestForgery::class, static fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        foreach (PermissionCode::cases() as $permission) {
            $this->ids[$permission->value] = $this->createPermission([
                'codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(),
            ]);
        }
        $admin = $this->createRole(['codigo' => 'session_admin']);
        foreach (OperationalAdminGuard::requiredPermissions() as $permission) {
            $this->attachPermission($admin, $this->ids[$permission->value]);
        }
        $this->actor = User::query()->findOrFail($this->createUser($admin, [
            'nombre_usuario' => 'session_admin', 'password' => Hash::make('Test-only-123'),
        ]));
        $this->role = Rol::query()->findOrFail($this->createRole(['codigo' => 'session_target']));
        $this->attachPermission((int) $this->role->id, $this->ids['articulos.ver']);
    }

    public function test_valid_native_chrome_post_saves_and_reload_remains_authenticated(): void
    {
        $token = $this->login();
        $this->nextRequest();
        $this->from(route('admin.roles.show', $this->role))->withHeader('Sec-Fetch-Site', 'same-origin')
            ->post(route('admin.roles.permissions.update', $this->role), [
                '_method' => 'PUT', '_token' => $token, '_role_permissions' => '1',
                'permissions' => [$this->ids['ventas.crear'], $this->ids['caja.sesiones.abrir']],
            ])->assertRedirect(route('admin.roles.show', $this->role))->assertSessionHas('success');
        $this->assertAuthenticatedAs($this->actor);
        $this->assertEqualsCanonicalizing([$this->ids['ventas.crear'], $this->ids['caja.sesiones.abrir']], $this->assigned());
        $this->nextRequest();
        $this->get(route('admin.roles.show', $this->role))->assertOk()->assertSee('Permisos del rol actualizados correctamente.');
        $this->assertAuthenticatedAs($this->actor);
    }

    public function test_expired_chrome_post_redirects_to_login_without_mutation(): void
    {
        $token = $this->login();
        $this->travel(61)->seconds();
        $this->nextRequest();
        $this->withHeader('Sec-Fetch-Site', 'same-origin')->post(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [$this->ids['ventas.crear']],
        ])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
        $this->get(route('login'))->assertOk()->assertSee('Tu sesión ha expirado por seguridad.')
            ->assertSee('Los cambios todavía no se han guardado.');
    }

    public function test_expired_token_without_verified_origin_returns_419_not_a_partial_save(): void
    {
        $token = $this->login();
        $this->travel(61)->seconds();
        $this->nextRequest();
        $this->post(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [$this->ids['ventas.crear']],
        ])->assertStatus(419);
        $this->assertGuest();
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_active_session_rejects_invalid_csrf_without_verified_origin(): void
    {
        $this->login();
        $this->nextRequest();
        $this->post(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => 'not-valid', 'permissions' => [$this->ids['ventas.crear']],
        ])->assertStatus(419);
        $this->assertAuthenticatedAs($this->actor);
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_enhanced_save_confirms_persistence_and_keeps_the_session_active(): void
    {
        $token = $this->login();
        $this->nextRequest();
        $this->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, '_editor_user_id' => $this->actor->id,
            'permissions' => [$this->ids['ventas.crear'], $this->ids['articulos.ver']],
        ])->assertOk()->assertJson(['saved' => true, 'message' => 'Permisos del rol actualizados correctamente.']);
        $this->assertAuthenticatedAs($this->actor);
        $this->assertCount(2, $this->assigned());
        $this->nextRequest();
        $response = $this->get(route('admin.roles.show', $this->role))->assertOk();
        foreach (['ventas.crear', 'articulos.ver'] as $code) {
            $response->assertSee('value="'.$this->ids[$code].'" checked', false);
        }
        $this->assertAuthenticatedAs($this->actor);
    }

    public function test_expired_enhanced_chrome_post_returns_401_without_a_redirect_or_mutation(): void
    {
        $token = $this->login();
        $this->travel(61)->seconds();
        $this->nextRequest();
        $response = $this->withHeader('Sec-Fetch-Site', 'same-origin')->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [$this->ids['ventas.crear']],
        ])->assertUnauthorized()->assertHeaderMissing('Location')->assertJsonMissing(['saved' => true]);
        $this->assertGuest();
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_enhanced_csrf_failure_is_419_and_never_claims_success(): void
    {
        $this->login();
        $this->nextRequest();
        $this->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => 'invalid', 'permissions' => [$this->ids['ventas.crear']],
        ])->assertStatus(419)->assertJsonMissing(['saved' => true]);
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_validation_failure_is_422_and_cannot_partially_replace_permissions(): void
    {
        $token = $this->login();
        $this->nextRequest();
        $this->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [$this->ids['ventas.crear'], 99999999],
        ])->assertUnprocessable()->assertJsonValidationErrors('permissions.1')->assertJsonMissing(['saved' => true]);
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_recovery_context_is_authorized_uncacheable_and_read_only(): void
    {
        $this->login();
        $before = $this->assigned();
        $this->nextRequest();
        $response = $this->getJson(route('admin.roles.show', $this->role))->assertOk()
            ->assertJson(['user_id' => (string) $this->actor->id, 'role_id' => (string) $this->role->id])
            ->assertJsonStructure(['csrf_token']);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame($this->app['session']->token(), $response->json('csrf_token'));
        $this->assertSame($before, $this->assigned());
        $this->assertAuthenticatedAs($this->actor);
    }

    public function test_revoked_manage_permission_returns_403_for_save_and_recovery(): void
    {
        $token = $this->login();
        DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->where('permiso_id', $this->ids['roles.permisos.gestionar'])->delete();
        $this->nextRequest();
        $this->getJson(route('admin.roles.show', $this->role))->assertForbidden();
        $this->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [],
        ])->assertForbidden()->assertJsonMissing(['saved' => true]);
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_anti_lockout_is_a_visible_422_with_full_rollback(): void
    {
        $token = $this->login();
        $before = DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->orderBy('permiso_id')->get()->toJson();
        $this->nextRequest();
        $this->postJson(route('admin.roles.permissions.update', $this->actor->rol_id), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [],
        ])->assertUnprocessable()->assertJsonStructure(['message'])->assertJsonMissing(['saved' => true]);
        $this->assertSame($before, DB::table('rol_permiso')->where('rol_id', $this->actor->rol_id)->orderBy('permiso_id')->get()->toJson());
        $this->assertAuthenticatedAs($this->actor);
    }

    public function test_form_opened_by_a_different_account_cannot_be_saved_as_the_current_user(): void
    {
        $token = $this->login();
        $this->nextRequest();
        $this->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, '_editor_user_id' => $this->actor->id + 99999,
            'permissions' => [$this->ids['ventas.crear']],
        ])->assertUnprocessable()->assertJsonValidationErrors('_editor_user_id');
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
    }

    public function test_reauthentication_and_context_check_do_not_replay_the_failed_save(): void
    {
        $token = $this->login();
        $this->travel(61)->seconds();
        $this->nextRequest();
        $this->withHeader('Sec-Fetch-Site', 'same-origin')->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $token, 'permissions' => [$this->ids['ventas.crear']],
        ])->assertUnauthorized();
        $this->flushHeaders();
        $newToken = $this->login();
        $this->assertNotSame($token, $newToken);
        $this->nextRequest();
        $this->getJson(route('admin.roles.show', $this->role))->assertOk();
        $this->assertSame([$this->ids['articulos.ver']], $this->assigned());
        $this->nextRequest();
        $this->postJson(route('admin.roles.permissions.update', $this->role), [
            '_method' => 'PUT', '_token' => $newToken, '_editor_user_id' => $this->actor->id,
            'permissions' => [$this->ids['ventas.crear']],
        ])->assertOk()->assertJson(['saved' => true]);
        $this->assertSame([$this->ids['ventas.crear']], $this->assigned());
    }

    private function login(): string
    {
        $this->get(route('login'))->assertOk();
        $token = $this->app['session']->token();
        $this->nextRequest();
        $this->post(route('login.store'), ['nombre_usuario' => 'session_admin', 'password' => 'Test-only-123', '_token' => $token])->assertRedirect(route('inicio'));
        $this->assertAuthenticatedAs($this->actor);
        $this->nextRequest();
        $this->get(route('admin.roles.show', $this->role))->assertOk();

        return $this->app['session']->token();
    }

    private function nextRequest(): void
    {
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        // Reload persisted session data and the guard as separate HTTP requests do.
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();
    }

    private function assigned(): array
    {
        return DB::table('rol_permiso')->where('rol_id', $this->role->id)->orderBy('permiso_id')->pluck('permiso_id')->all();
    }
}
