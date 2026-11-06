<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as FrameworkPreventRequestForgery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The session cookie is host-only on the API, but the console runs on another
 * host and reads XSRF-TOKEN through JavaScript, so this one cookie alone needs a
 * parent domain. It holds only the encrypted CSRF token, which is useless without
 * a custom header that CORS refuses to other origins; see docs/AUTHENTICATION.md.
 */
class PreventRequestForgery extends FrameworkPreventRequestForgery
{
    /**
     * @param  Request  $request
     * @param  array<string, mixed>  $config
     */
    protected function newCookie($request, $config): Cookie
    {
        return parent::newCookie($request, [
            ...$config,
            'domain' => $config['xsrf_cookie_domain'] ?? null,
        ]);
    }
}
