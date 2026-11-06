<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Auth\OriginNotAllowedException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the one session route Laravel exempts from CSRF checks: it registers
 * /broadcasting/auth without them, so a page on a tenant application, which is the
 * same site as the API, could use the session there. This applies the rule the API
 * routes already live by: only the origins in SANCTUM_STATEFUL_DOMAINS get to act
 * with a session, and a request naming no origin at all is not one of them.
 */
final class EnsureFirstPartyOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            throw new OriginNotAllowedException;
        }

        return $next($request);
    }
}
