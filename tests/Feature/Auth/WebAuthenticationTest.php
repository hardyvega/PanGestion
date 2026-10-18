<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_loads_with_accessible_branding_and_form(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('<title>Iniciar sesión | Almapan</title>', false)
            ->assertSee('<h1>Almapan</h1>', false)
            ->assertSee('aria-label="Identidad de Almapan"', false)
            ->assertDontSee('PanGestión')
            ->assertSee('Nombre de usuario')
            ->assertSee('Contraseña')
            ->assertSee('data-password-toggle', false)
            ->assertSee('autocomplete="off"', false)
            ->assertSee('data-login-form', false)
            ->assertDontSee('Registrarse');
    }

    public function test_valid_login_uses_username_case_insensitively_and_redirects_to_inicio(): void
    {
        $user = $this->createUser([
            'nombre_usuario' => 'OperadorCaja',
            'password' => 'clave-sin-mayuscula-123',
        ]);
        $this->get('/login');

        $response = $this->post('/login', [
            'nombre_usuario' => ' operadorcaja ',
            'password' => 'clave-sin-mayuscula-123',
        ]);

        $response->assertRedirect(route('inicio'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->ultimo_acceso);
    }

    public function test_login_regenerates_the_session_id(): void
    {
        $this->createUser();
        $this->get('/login');
        $before = $this->app['session']->getId();

        $this->post('/login', [
            'nombre_usuario' => 'operador',
            'password' => 'ClaveSegura123',
        ]);

        $this->assertNotSame($before, $this->app['session']->getId());
    }

    public function test_invalid_credentials_use_a_generic_message(): void
    {
        $this->createUser();

        $response = $this->from('/login')->post('/login', [
            'nombre_usuario' => 'operador',
            'password' => 'incorrecta123',
        ]);

        $response->assertRedirect('/login')
            ->assertSessionHasErrors([
                'nombre_usuario' => 'Nombre de usuario o contraseña incorrectos.',
            ]);
        $this->assertGuest();
    }

    public function test_nonexistent_username_uses_the_same_generic_message(): void
    {
        $response = $this->from('/login')->post('/login', [
            'nombre_usuario' => 'no_existe',
            'password' => 'incorrecta123',
        ]);

        $response->assertRedirect('/login')
            ->assertSessionHasErrors([
                'nombre_usuario' => 'Nombre de usuario o contraseña incorrectos.',
            ]);
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login_and_receives_the_expected_message(): void
    {
        $this->createUser(['activo' => false]);

        $response = $this->from('/login')->post('/login', [
            'nombre_usuario' => 'operador',
            'password' => 'ClaveSegura123',
        ]);

        $response->assertRedirect('/login')
            ->assertSessionHasErrors([
                'nombre_usuario' => 'Tu cuenta está inactiva. Contacta al administrador.',
            ]);
        $this->assertGuest();
    }

    public function test_email_is_not_an_authentication_identifier(): void
    {
        $this->createUser(['correo' => 'operador@example.test']);

        $response = $this->from('/login')->post('/login', [
            'nombre_usuario' => 'operador@example.test',
            'password' => 'ClaveSegura123',
        ]);

        $response->assertSessionHasErrors('nombre_usuario');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_by_username_and_ip(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'nombre_usuario' => 'limite',
                'password' => 'incorrecta123',
            ])->assertRedirect('/login');
        }

        $response = $this->post('/login', [
            'nombre_usuario' => 'limite',
            'password' => 'incorrecta123',
        ]);

        $response->assertStatus(429)
            ->assertSessionHasErrors([
                'nombre_usuario' => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
            ]);
    }

    public function test_guest_is_redirected_from_inicio_and_authenticated_user_can_see_it(): void
    {
        $this->get('/inicio')->assertRedirect('/login');

        $user = $this->createUser();
        $this->actingAs($user)
            ->get('/inicio')
            ->assertOk()
            ->assertSee('Resumen operativo')
            ->assertSee('<h1 id="dashboard-title">Bienvenido</h1>', false)
            ->assertSee('Cerrar sesión');
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs($this->createUser())
            ->get('/login')
            ->assertRedirect('/inicio');
    }

    public function test_logout_invalidates_the_session_and_get_logout_is_not_available(): void
    {
        $this->actingAs($this->createUser());

        $this->get('/logout')->assertMethodNotAllowed();

        $this->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/inicio')->assertRedirect('/login');
    }

    public function test_registration_is_not_publicly_available(): void
    {
        $this->get('/register')->assertNotFound();
    }

    private function createUser(array $overrides = []): User
    {
        $token = Str::lower(Str::random(12));
        $roleId = DB::table('roles')->insertGetId([
            'codigo' => 'f1_'.$token,
            'nombre' => 'Rol F1 '.$token,
            'activo' => true,
        ]);

        $attributes = array_merge([
            'rol_id' => $roleId,
            'nombre' => 'Ana',
            'apellido' => 'Operadora',
            'nombre_usuario' => 'operador',
            'correo' => null,
            'password' => Hash::make('ClaveSegura123'),
            'activo' => true,
        ], $overrides);

        if (isset($overrides['password'])) {
            $attributes['password'] = Hash::make($overrides['password']);
        }

        $id = DB::table('usuarios')->insertGetId($attributes);

        return User::query()->findOrFail($id);
    }
}
