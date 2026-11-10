<?php

use App\Http\Middleware\PreventPrivateResponseCaching;
use App\Http\Middleware\ReadOnlySession;
use App\Http\Responses\LocalizedErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [PreventPrivateResponseCaching::class]);
        $middleware->prependToPriorityList(StartSession::class, ReadOnlySession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['contact_name', 'contact_phone']);
        $exceptions->respond(LocalizedErrorResponse::render(...));
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->routeIs('admin.roles.permissions.update') || $request->expectsJson()) {
                return null;
            }

            return redirect()->guest(route('login'))->with('permission_session_notice',
                'Tu sesión ha expirado por seguridad. Debes iniciar sesión nuevamente. Los cambios todavía no se han guardado.');
        });
    })->create();
