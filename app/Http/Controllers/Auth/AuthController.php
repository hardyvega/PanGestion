<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('inicio');
        }

        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $throttleKey = $request->throttleKey();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return redirect()
                ->route('login')
                ->withInput($request->only('nombre_usuario'))
                ->withErrors([
                    'nombre_usuario' => 'Demasiados intentos. Espera un momento y vuelve a intentarlo.',
                ])
                ->setStatusCode(429);
        }

        $user = User::query()
            ->whereRaw('LOWER(nombre_usuario) = ?', [$request->normalizedUsername()])
            ->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return redirect()
                ->route('login')
                ->withInput($request->only('nombre_usuario'))
                ->withErrors([
                    'nombre_usuario' => 'Nombre de usuario o contraseña incorrectos.',
                ]);
        }

        if (! $user->activo) {
            RateLimiter::hit($throttleKey, 60);

            return redirect()
                ->route('login')
                ->withInput($request->only('nombre_usuario'))
                ->withErrors([
                    'nombre_usuario' => 'Tu cuenta está inactiva. Contacta al administrador.',
                ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill([
            'ultimo_acceso' => now(),
        ])->save();

        return redirect()->intended(route('inicio'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
