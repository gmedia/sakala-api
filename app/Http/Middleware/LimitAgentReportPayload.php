<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitAgentReportPayload
{
    private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config(
            'sakala.pilot_limits.log_bounds.max_total_bytes',
            self::DEFAULT_MAX_BYTES,
        );

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > $maxBytes) {
            abort(413, 'The report payload is too large.');
        }

        if (strlen($request->getContent()) > $maxBytes) {
            abort(413, 'The report payload is too large.');
        }

        return $next($request);
    }
}
