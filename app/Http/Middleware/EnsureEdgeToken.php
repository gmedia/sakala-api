<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\EdgeToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Sakala Edge with its own bearer token (EdgeToken), apart from
 * user sessions and agent tokens: a missing or unknown token answers 401, a
 * revoked one 403. The token reaches nothing but the Edge routes.
 */
final class EnsureEdgeToken
{
    /** Request attribute holding the authenticated EdgeToken. */
    public const ATTRIBUTE = 'edge_token';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        $edgeToken = EdgeToken::query()->where('token_hash', EdgeToken::hash($token))->first();

        if ($edgeToken === null) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized');
        }

        if ($edgeToken->isRevoked()) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden');
        }

        if ($edgeToken->last_used_at === null
            || $edgeToken->last_used_at->lt(now()->subSeconds(EdgeToken::LAST_USED_RESOLUTION_SECONDS))) {
            $edgeToken->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set(self::ATTRIBUTE, $edgeToken);

        return $next($request);
    }
}
