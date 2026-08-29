<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitAgentHeartbeatPayload
{
    private const MAX_BYTES = 256 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->header('Content-Length');

        if ($contentLength !== null && (int) $contentLength > self::MAX_BYTES) {
            abort(413, 'Heartbeat payload is too large.');
        }

        return $next($request);
    }
}
