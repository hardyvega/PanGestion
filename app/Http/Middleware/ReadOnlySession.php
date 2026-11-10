<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

final class ReadOnlySession
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->driver();
        $session->setId($request->cookies->get($session->getName()));
        $session->setRequestOnHandler($request);
        $session->start();
        $request->setLaravelSession($session);

        return $next($request);
    }
}
