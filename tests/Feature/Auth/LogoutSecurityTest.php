<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PermissionCode;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

final class LogoutSecurityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    public function test_logout_destroys_the_previous_session_and_rotates_its_id_and_csrf_token(): void
    {
        $this->actor();
        $this->post('/login', ['nombre_usuario' => 'logout_operator', 'password' => 'Testing123'])
            ->assertRedirect('/inicio');
        $this->withSession(['private_marker' => 'discard'])->get('/inicio')->assertOk();
        $session = $this->app['session']->driver();
        $oldId = $session->getId();
        $oldToken = $session->token();
        $loginKey = $this->app['auth']->guard()->getName();
        $this->assertNotEmpty($session->getHandler()->read($oldId));

        $response = $this->withCookie(config('session.cookie'), $oldId)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $response->assertSessionMissing(['private_marker', $loginKey]);
        $this->assertNotSame($oldId, $session->getId());
        $this->assertNotSame($oldToken, $session->token());
        $this->assertNotEmpty($session->token());
        $this->assertEmpty($session->getHandler()->read($oldId));

        $this->app['auth']->forgetGuards();
        $this->withCookie(config('session.cookie'), $oldId)->get('/inicio')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_every_private_web_route_requires_authentication_and_is_denied_after_logout(): void
    {
        $this->actingAs($this->actor(PermissionCode::cases()));
        $this->post('/logout')->assertRedirect('/login');
        $routes = collect(Router::getRoutes()->getRoutes())->filter(static fn (Route $route): bool =>
            str_starts_with($route->getActionName(), 'App\\Http\\Controllers\\')
            && ! in_array($route->getName(), ['login', 'login.store'], true));
        $this->assertNotEmpty($routes);
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        foreach ($routes as $route) {
            $this->assertContains('auth', $route->gatherMiddleware(), $route->getName());
            $url = route($route->getName(), array_fill_keys($route->parameterNames(), 1));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->call($method, $url)->assertRedirect('/login');
            }
        }

        $this->assertSame([], $writes, 'A logged-out request must not mutate operational data.');
        $this->getJson('/inicio')->assertUnauthorized();
        $this->postJson('/articulos', [])->assertUnauthorized();
    }

    public function test_private_html_and_logout_redirect_are_never_cacheable(): void
    {
        $this->actingAs($this->actor(PermissionCode::cases()));
        foreach ([
            'inicio', 'sales.create', 'orders.index', 'inventory.index', 'receipts.index',
            'inventory.movements', 'inventory.counts', 'inventory.wastes', 'inventory.adjustments.create',
            'recipes.index', 'productions.index', 'articles.index', 'suppliers.index',
            'cash.index', 'cash.movements', 'personnel.index', 'attendance.mark', 'attendance.index',
            'personnel.pay-report', 'admin.users.index', 'admin.roles.index', 'admin.permissions.index',
        ] as $route) {
            $response = $this->get(route($route))->assertOk();
            $this->assertPrivateCachePolicy($response->baseResponse);
        }
        $this->assertPrivateCachePolicy($this->post('/logout')->assertRedirect('/login')->baseResponse);
    }

    public function test_permission_denials_remain_denied_and_are_not_cacheable(): void
    {
        $this->actingAs($this->actor());
        $response = $this->get('/administracion/usuarios')->assertForbidden();
        $this->assertPrivateCachePolicy($response->baseResponse);
    }

    public function test_public_login_and_static_responses_keep_their_existing_cache_policy(): void
    {
        $this->assertFalse($this->get('/login')->assertOk()->headers->hasCacheControlDirective('no-store'));
        $request = Request::create('/build/assets/example.css');
        $request->setRouteResolver(static fn (): Route => new Route('GET', 'build/assets/example.css', static fn () => null));
        $response = new Response('body {}', 200, ['Content-Type' => 'text/css', 'Cache-Control' => 'public, max-age=3600']);
        $before = $response->headers->all();
        $actual = app(\App\Http\Middleware\PreventPrivateResponseCaching::class)->handle($request, static fn () => $response);
        $this->assertSame($before, $actual->headers->all());
    }

    public function test_private_json_contract_is_unchanged(): void
    {
        $this->actingAs($this->actor([PermissionCode::SalesCreate]));
        $response = $this->getJson(route('sales.search', ['q' => 'sin_resultados']))->assertOk();
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_logout_only_accepts_post_with_the_framework_csrf_protection_enabled(): void
    {
        // Exercise production CSRF validation without changing the testing environment.
        $this->app->bind(PreventRequestForgery::class, static fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingAs($this->actor())->get('/inicio')->assertOk();
        $this->get('/logout')->assertMethodNotAllowed();
        $token = $this->app['session']->token();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticated();
        $this->post('/logout', ['_token' => 'incorrect-token'])->assertStatus(419);
        $this->assertAuthenticated();
        $this->post('/logout', ['_token' => $token])->assertRedirect('/login');
        $this->assertGuest();
    }

    private function assertPrivateCachePolicy(Response $response): void
    {
        foreach (['private', 'no-store', 'no-cache', 'must-revalidate'] as $directive) {
            $this->assertTrue($response->headers->hasCacheControlDirective($directive), $response->headers->get('Cache-Control'));
        }
        $this->assertSame('0', (string) $response->headers->getCacheControlDirective('max-age'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
    }

    private function actor(array $permissions = []): User
    {
        $roleId = $this->createRole();
        foreach ($permissions as $permission) {
            $permissionId = $this->createPermission([
                'codigo' => $permission->value, 'nombre' => $permission->label(), 'modulo' => $permission->module(),
            ]);
            $this->attachPermission($roleId, $permissionId);
        }

        return User::query()->findOrFail($this->createUser($roleId, [
            'nombre_usuario' => 'logout_operator', 'password' => Hash::make('Testing123'),
        ]));
    }
}
