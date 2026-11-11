<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Auth\EmailDeliveryUnavailableException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards endpoints whose whole purpose is sending an email. Route middleware
 * runs before the Form Request, so validation cannot reveal anything about the
 * submitted address while delivery is off.
 */
final class EnsureEmailDeliveryAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sakala.email_delivery_enabled')) {
            throw new EmailDeliveryUnavailableException;
        }

        return $next($request);
    }
}
