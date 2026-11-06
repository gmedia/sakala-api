<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Auth\LegalAcceptanceRequiredException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards what starts using the service: creating a project, inspecting it, and
 * deploying. Reading, deleting, stopping work, exporting, and leaving stay open,
 * so nobody needs to accept anything to take their data out.
 */
final class EnsureLegalAcceptance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->requiresLegalAcceptance()) {
            throw new LegalAcceptanceRequiredException;
        }

        return $next($request);
    }
}
