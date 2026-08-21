<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AgentStatus;
use App\Models\Agent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAgentToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        $agentId = $request->header('X-Agent-Id');

        if (! $authHeader || ! $agentId) {
            abort(401, 'Unauthorized');
        }

        if (! Str::startsWith($authHeader, 'Bearer ')) {
            abort(401, 'Unauthorized');
        }

        $token = Str::substr($authHeader, 7);

        $agent = Agent::where('id', $agentId)->first();

        if (! $agent) {
            abort(401, 'Unauthorized');
        }

        if (! password_verify($token, $agent->token_hash)) {
            abort(401, 'Unauthorized');
        }

        if ($agent->status !== AgentStatus::Active) {
            abort(403, 'Forbidden');
        }

        $request->merge(['agent' => $agent]);

        return $next($request);
    }
}
