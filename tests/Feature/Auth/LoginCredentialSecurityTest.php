<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockOneRecords;
use Tests\TestCase;

final class LoginCredentialSecurityTest extends TestCase
{
    use CreatesBlockOneRecords;
    use RefreshDatabase;

    private const TEST_PASSWORD = 'CredentialSafety123!';

    public function test_initial_html_has_empty_credentials_and_discourages_browser_autofill(): void
    {
        $this->actor();
        $response = $this->get('/login')->assertOk()->assertDontSee(self::TEST_PASSWORD);
        $html = $this->html($response);

        $this->assertSame('', $html->evaluate('string(//input[@name="nombre_usuario"]/@value)'));
        $this->assertSame('off', $html->evaluate('string(//form[@data-login-form]/@autocomplete)'));
        $this->assertSame('off', $html->evaluate('string(//input[@name="nombre_usuario"]/@autocomplete)'));
        $this->assertSame('off', $html->evaluate('string(//input[@name="password"]/@autocomplete)'));
        $this->assertSame('POST', $html->evaluate('string(//form[@data-login-form]/@method)'));
        $this->assertSame(1, $html->query('//form[@data-login-form]//input[@name="_token"]')->length);
        $this->assertEmptyPassword($response);
    }

    public function test_password_is_not_rendered_even_if_old_input_contains_one(): void
    {
        $response = $this->withSession(['_old_input' => [
            'nombre_usuario' => 'credential_operator',
            'password' => self::TEST_PASSWORD,
        ]])->get('/login')->assertOk()->assertDontSee(self::TEST_PASSWORD);

        $this->assertSame('credential_operator', $this->html($response)->evaluate('string(//input[@name="nombre_usuario"]/@value)'));
        $this->assertEmptyPassword($response);
    }

    #[DataProvider('failedAttempts')]
    public function test_failed_logins_never_return_flash_or_log_the_submitted_password(string $scenario): void
    {
        $this->actor($scenario !== 'inactive');
        $messages = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$messages): void {
            $messages[] = [$event->message, $event->context];
        });
        $username = $scenario === 'unknown' ? 'missing_operator' : 'credential_operator';
        $password = $scenario === 'inactive' ? self::TEST_PASSWORD : 'RejectedCredential456!';
        $canary = $password;

        if ($scenario === 'validation') {
            $username = '';
        }
        if ($scenario === 'password_shape') {
            $password = [$password];
        }
        if ($scenario === 'throttled') {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                RateLimiter::hit($username.'|127.0.0.1', 60);
            }
        }

        $response = $this->from('/login')->post('/login', [
            'nombre_usuario' => $username, 'password' => $password,
        ]);
        $response->assertStatus($scenario === 'throttled' ? 429 : 302)
            ->assertHeader('Location', route('login'))->assertSessionHasErrors();
        $this->assertGuest();
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertStringNotContainsString($canary, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($canary, json_encode($messages, JSON_THROW_ON_ERROR));
        $response->assertDontSee($canary);

        $login = $this->get('/login')->assertOk()->assertDontSee($canary);
        $this->assertEmptyPassword($login);
    }

    public static function failedAttempts(): array
    {
        return array_map(static fn (string $scenario): array => [$scenario], [
            'incorrect', 'unknown', 'inactive', 'validation', 'password_shape', 'throttled',
        ]);
    }

    public function test_logout_discards_old_username_and_returns_an_empty_login_without_private_access(): void
    {
        $this->actor();
        $this->post('/login', [
            'nombre_usuario' => 'credential_operator', 'password' => self::TEST_PASSWORD,
        ])->assertRedirect('/inicio');
        $this->assertStringNotContainsString(self::TEST_PASSWORD, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->withSession(['_old_input' => ['nombre_usuario' => 'credential_operator']]);
        $this->withCookie(config('session.cookie'), session()->getId())
            ->post('/logout')->assertRedirect('/login')->assertSessionMissing('_old_input');
        $this->assertGuest();
        $login = $this->get('/login')->assertOk()->assertDontSee(self::TEST_PASSWORD);
        $this->assertSame('', $this->html($login)->evaluate('string(//input[@name="nombre_usuario"]/@value)'));
        $this->assertEmptyPassword($login);
        $this->get('/inicio')->assertRedirect('/login');
        $this->post('/articulos', [])->assertRedirect('/login');
    }

    public function test_authentication_code_does_not_use_browser_storage_or_password_old_input(): void
    {
        foreach (glob(resource_path('js/*.js')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:localStorage|sessionStorage)\b/', file_get_contents($file));
        }
        $this->assertDoesNotMatchRegularExpression('/old\s*\(\s*[\'"]password[\'"]/', file_get_contents(resource_path('views/auth/login.blade.php')));
    }

    private function assertEmptyPassword(TestResponse $response): void
    {
        $inputs = $this->html($response)->query('//input[@name="password"]');
        $this->assertSame(1, $inputs->length);
        $this->assertSame('password', $inputs->item(0)->getAttribute('type'));
        $this->assertSame('', $inputs->item(0)->getAttribute('value'));
    }

    private function html(TestResponse $response): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function actor(bool $active = true): User
    {
        return User::query()->findOrFail($this->createUser($this->createRole(), [
            'nombre_usuario' => 'credential_operator',
            'password' => Hash::make(self::TEST_PASSWORD),
            'activo' => $active,
        ]));
    }
}
