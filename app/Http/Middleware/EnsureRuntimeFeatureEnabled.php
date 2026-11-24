<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Runtime\RuntimeFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 for the endpoints of an ADR-022 feature while it is off
 * (RuntimeFeatures), as if they did not exist, so an installation that has
 * not turned it on behaves exactly as before. The parameter is
 * RuntimeFeatures::WORKERS or RuntimeFeatures::SCHEDULES.
 */
final class EnsureRuntimeFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(RuntimeFeatures::enabled($feature), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
