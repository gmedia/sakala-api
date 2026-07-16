<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->renderable(function (HttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $messages = [
                401 => 'Unauthenticated.',
                403 => 'Forbidden.',
                419 => 'CSRF token mismatch.',
                429 => 'Too Many Requests.',
            ];

            $status = $e->getStatusCode();

            if (isset($messages[$status])) {
                return response()->json([
                    'message' => $messages[$status],
                ], $status);
            }

            return null;
        });
    })->create();
