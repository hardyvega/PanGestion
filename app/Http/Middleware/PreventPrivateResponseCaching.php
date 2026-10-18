<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PreventPrivateResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $protected = collect($request->route()?->gatherMiddleware() ?? [])
            ->contains(static fn ($middleware): bool => is_string($middleware) && (
                $middleware === 'auth'
                || str_starts_with($middleware, 'auth:')
                || $middleware === Authenticate::class
                || str_starts_with($middleware, Authenticate::class.':')
            ));

        if ($protected && str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
