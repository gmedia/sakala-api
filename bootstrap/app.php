<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:web']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // The API is only reachable through the edge proxy chain
        // (Cloudflare -> host Caddy -> nginx -> PHP-FPM), so without trusting
        // it every request appears to come from the last internal hop. That
        // breaks the per-IP rate limiters on login, registration, OAuth, and
        // email verification, which would then throttle all users together,
        // and it makes audit logs useless.
        //
        // Only private ranges and loopback are trusted: those are the hops we
        // operate. A forwarded header arriving from anywhere else is ignored,
        // so a client cannot spoof its own address.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'broadcasting/auth'),
        );
    })->create();
