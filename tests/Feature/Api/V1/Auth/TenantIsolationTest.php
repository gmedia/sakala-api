<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureFirstPartyOrigin;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as FrameworkPreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\TestingPreventRequestForgery;

/*
 * Tenant applications run on *.run.sakala.dev, which is the same site as the API
 * and the console. SameSite=Lax therefore does not stop their pages from making
 * the browser send the session cookie to the API, so these tests replay what a
 * browser sends from such a page, using a real session and real CSRF checks.
 */

uses(LazilyRefreshDatabase::class);

const TENANT_ORIGIN = 'https://evil.run.sakala.dev';
const CONSOLE_ORIGIN = 'https://app.sakala.dev';

beforeEach(function (): void {
    config()->set('session.driver', 'database');
    config()->set('session.cookie', '__Host-sakala-api-session');
    config()->set('session.domain', null);
    config()->set('session.secure', true);
    config()->set('session.xsrf_cookie_domain', '.sakala.dev');
    config()->set('sanctum.stateful', ['app.sakala.dev', 'api.sakala.dev']);
    config()->set('cors.allowed_origins', [CONSOLE_ORIGIN]);

    // The framework skips CSRF checks in tests. Everything here is about them.
    config()->set('sanctum.middleware.validate_csrf_token', TestingPreventRequestForgery::class);
    app()->bind(PreventRequestForgery::class, TestingPreventRequestForgery::class);
});

/**
 * A browser request reaches a new PHP process. In a test the app stays alive, and
 * a session store started by an earlier request would still know the login even
 * for a request whose middleware never starts a session, so it must be dropped.
 */
function asNextRequest(object $test): object
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    auth()->forgetGuards();
    $test->flushHeaders();
    (function (): void {
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    })->call($test);

    // Without this the JSON helpers would drop the cookies, as no browser does.
    return $test->withCredentials();
}

/**
 * Logs in the way the console does and returns the two cookies a browser keeps.
 *
 * @return array{session: Cookie, xsrf: Cookie}
 */
function consoleLogin(object $test, User $user): array
{
    $csrf = asNextRequest($test)->withHeader('Origin', CONSOLE_ORIGIN)->get('/sanctum/csrf-cookie');
    $xsrf = $csrf->getCookie('XSRF-TOKEN', false);
    $session = $csrf->getCookie('__Host-sakala-api-session', false);

    $login = asNextRequest($test)->withHeader('Origin', CONSOLE_ORIGIN)
        ->withHeader('X-XSRF-TOKEN', $xsrf->getValue())
        ->withUnencryptedCookie($xsrf->getName(), $xsrf->getValue())
        ->withUnencryptedCookie($session->getName(), $session->getValue())
        ->postJson(route('api.v1.auth.login'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    return [
        'session' => $login->getCookie('__Host-sakala-api-session', false),
        'xsrf' => $login->getCookie('XSRF-TOKEN', false),
    ];
}

function withBrowserCookies(object $test, array $cookies, string $origin): object
{
    return asNextRequest($test)->withHeader('Origin', $origin)
        ->withUnencryptedCookie($cookies['xsrf']->getName(), $cookies['xsrf']->getValue())
        ->withUnencryptedCookie($cookies['session']->getName(), $cookies['session']->getValue());
}

// ─── Cookie shape ────────────────────────────────────────────────────────────

test('the session cookie is host-only and meets the __Host- rules', function (): void {
    $cookie = $this->withHeader('Origin', CONSOLE_ORIGIN)
        ->get('/sanctum/csrf-cookie')
        ->getCookie('__Host-sakala-api-session', false);

    expect($cookie->getDomain())->toBeNull()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

test('only XSRF-TOKEN gets the parent domain, and JavaScript can read it', function (): void {
    $response = $this->withHeader('Origin', CONSOLE_ORIGIN)->get('/sanctum/csrf-cookie');

    $cookie = $response->getCookie('XSRF-TOKEN', false);

    expect($cookie->getDomain())->toBe('.sakala.dev')
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->isSecure())->toBeTrue();
});

test('the web group and Sanctum both use the middleware that scopes XSRF-TOKEN', function (): void {
    expect(app('router')->getMiddlewareGroups()['web'])
        ->toContain(PreventRequestForgery::class)
        ->not->toContain(FrameworkPreventRequestForgery::class);

    $config = require base_path('config/sanctum.php');

    expect($config['middleware']['validate_csrf_token'])->toBe(PreventRequestForgery::class);
});

test('same-site requests get no shortcut past the CSRF token', function (): void {
    $allowSameSite = (new ReflectionClass(FrameworkPreventRequestForgery::class))->getStaticPropertyValue('allowSameSite');

    expect($allowSameSite)->toBeFalse();
});

// ─── API routes: a tenant page cannot act with the session ───────────────────

test('the console can act with the session and its CSRF token, which is what the tenant tests are measured against', function (): void {
    $cookies = consoleLogin($this, User::factory()->create());

    withBrowserCookies($this, $cookies, CONSOLE_ORIGIN)
        ->withHeader('X-XSRF-TOKEN', $cookies['xsrf']->getValue())
        ->getJson(route('api.v1.auth.user'))
        ->assertOk();
});

test('a form post from a tenant page carrying the victim session is not authenticated', function (): void {
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->withHeader('Referer', TENANT_ORIGIN.'/attack')
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->post(route('projects.store'), ['name' => 'owned-by-attacker'], ['Accept' => 'application/json'])
        ->assertUnauthorized();

    expect(Project::query()->count())->toBe(0);
});

test('a tenant page cannot reach destructive routes through a method override either', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->post(route('projects.destroy', $project), ['_method' => 'DELETE'], ['Accept' => 'application/json'])
        ->assertUnauthorized();

    expect($project->fresh())->not->toBeNull();
});

test('a tenant page cannot delete the account behind the victim', function (): void {
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->post(route('api.v1.auth.account.destroy'), ['_method' => 'DELETE', 'email' => $user->email, 'current_password' => 'password'], ['Accept' => 'application/json'])
        ->assertUnauthorized();

    expect($user->fresh())->not->toBeNull();
});

test('a tenant page cannot read the account or a secret value with the session', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['is_secret' => true]);
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->getJson(route('api.v1.app.projects.environment-variables.value', [$project, $variable]))
        ->assertUnauthorized();

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->getJson(route('api.v1.auth.account.export'))
        ->assertUnauthorized();
});

test('a request with no origin and no referer is never treated as the console', function (): void {
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    asNextRequest($this)->withUnencryptedCookie($cookies['session']->getName(), $cookies['session']->getValue())
        ->postJson(route('projects.store'), ['name' => 'no-origin'])
        ->assertUnauthorized();

    expect(Project::query()->count())->toBe(0);
});

test('a tenant slug that merely starts like the console host is not the console', function (string $origin): void {
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, $origin)
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
})->with(['https://app.run.sakala.dev', 'https://app.sakala.dev.run.sakala.dev', 'https://api.run.sakala.dev']);

test('CORS gives a tenant origin nothing, so it cannot send the CSRF header or read answers', function (): void {
    $response = $this->withHeader('Origin', TENANT_ORIGIN)
        ->withHeader('Access-Control-Request-Method', 'POST')
        ->withHeader('Access-Control-Request-Headers', 'x-xsrf-token')
        ->options(route('projects.store'));

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe(TENANT_ORIGIN);
});

// ─── Web routes: the only POST is /broadcasting/auth ─────────────────────────

test('the only state-changing web route is broadcasting auth, so a new one must come through here', function (): void {
    $webPosts = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('web', $route->gatherMiddleware(), true))
        ->filter(fn ($route): bool => array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [])
        ->map(fn ($route): string => $route->uri())
        ->values()
        ->all();

    expect($webPosts)->toBe(['broadcasting/auth']);
});

test('the framework leaves broadcasting auth without CSRF checks, so it needs the origin rule', function (): void {
    $route = app('router')->getRoutes()->match(request()->create('/broadcasting/auth', 'POST'));

    expect($route->excludedMiddleware())->toContain(FrameworkPreventRequestForgery::class)
        ->and($route->gatherMiddleware())->toContain(EnsureFirstPartyOrigin::class);
});

test('a tenant page cannot use the session on broadcasting auth', function (): void {
    Broadcast::shouldReceive('auth')->never();
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);
    $body = ['channel_name' => 'private-users.'.$user->id, 'socket_id' => '1.1'];

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->post('/broadcasting/auth', $body)
        ->assertForbidden()
        ->assertJsonPath('code', 'ORIGIN_NOT_ALLOWED');

    withBrowserCookies($this, $cookies, TENANT_ORIGIN)
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->get('/broadcasting/auth?'.http_build_query($body))
        ->assertForbidden()
        ->assertJsonPath('code', 'ORIGIN_NOT_ALLOWED');
});

test('broadcasting auth refuses a request that names no origin at all', function (): void {
    Broadcast::shouldReceive('auth')->never();
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    asNextRequest($this)
        ->withUnencryptedCookie($cookies['session']->getName(), $cookies['session']->getValue())
        ->post('/broadcasting/auth', ['channel_name' => 'private-users.'.$user->id, 'socket_id' => '1.1'])
        ->assertForbidden();
});

test('the console keeps working on broadcasting auth with its host-only session', function (): void {
    Broadcast::shouldReceive('auth')->once()->andReturn(['auth' => 'ok']);
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, CONSOLE_ORIGIN)
        ->withHeader('X-XSRF-TOKEN', $cookies['xsrf']->getValue())
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->post('/broadcasting/auth', ['channel_name' => 'private-users.'.$user->id, 'socket_id' => '1.1'])
        ->assertOk();
});

test('the XSRF cookie a tenant server sees is no use as a form token', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $cookies = consoleLogin($this, $user);

    // Same-site form post from the console origin itself, which does get a
    // session: the encrypted cookie value is not the session's token.
    withBrowserCookies($this, $cookies, CONSOLE_ORIGIN)
        ->withHeader('Sec-Fetch-Site', 'same-site')
        ->post(route('projects.destroy', $project), ['_method' => 'DELETE', '_token' => $cookies['xsrf']->getValue()], ['Accept' => 'application/json'])
        ->assertStatus(419);

    expect($project->fresh()?->trashed())->toBeFalse();
});

// ─── Cookie tossing and old cookies ──────────────────────────────────────────

test('a tossed XSRF-TOKEN fails closed on the console path', function (string $tossed): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $cookies = consoleLogin($this, $user);

    withBrowserCookies($this, $cookies, CONSOLE_ORIGIN)
        ->withHeader('X-XSRF-TOKEN', $tossed)
        ->deleteJson(route('projects.destroy', $project))
        ->assertStatus(419);

    expect($project->fresh()?->trashed())->toBeFalse();
})->with(['not-even-encrypted', 'eyJpdiI6Im5vcGUifQ==']);

test('another account\'s valid XSRF cookie does not pass for this session', function (): void {
    $victim = User::factory()->create();
    $project = Project::factory()->for($victim)->create();
    $attacker = consoleLogin($this, User::factory()->create());
    asNextRequest($this);
    $cookies = consoleLogin($this, $victim);

    withBrowserCookies($this, $cookies, CONSOLE_ORIGIN)
        ->withHeader('X-XSRF-TOKEN', $attacker['xsrf']->getValue())
        ->deleteJson(route('projects.destroy', $project))
        ->assertStatus(419);

    expect($project->fresh()?->trashed())->toBeFalse();
});

test('a session value issued under the old cookie name is ignored', function (): void {
    $user = User::factory()->create();
    $cookies = consoleLogin($this, $user);

    $sessionId = CookieValuePrefix::remove(Crypt::decrypt($cookies['session']->getValue(), false));
    $oldName = 'sakala-api-session';
    $issuedUnderOldName = Crypt::encrypt(CookieValuePrefix::create($oldName, Crypt::getKey()).$sessionId, false);

    // Sent under the old name, and copied by hand to the new name.
    asNextRequest($this)->withHeader('Origin', CONSOLE_ORIGIN)
        ->withUnencryptedCookie($oldName, $issuedUnderOldName)
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();

    asNextRequest($this)->withHeader('Origin', CONSOLE_ORIGIN)
        ->withUnencryptedCookie('__Host-sakala-api-session', $issuedUnderOldName)
        ->getJson(route('api.v1.auth.user'))
        ->assertUnauthorized();
});

test('the OAuth state survives the host-only session across redirect and callback', function (): void {
    config()->set('services.github_app.client_id', 'client');
    config()->set('services.github_app.redirect', 'https://api.sakala.dev/auth/github/callback');
    config()->set('sakala.console_url', CONSOLE_ORIGIN);

    $redirect = $this->get(route('auth.github.redirect'))->assertRedirect();
    $session = $redirect->getCookie('__Host-sakala-api-session', false);

    expect($session->getDomain())->toBeNull();

    parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    // A state nobody issued fails on the same session.
    $this->withUnencryptedCookie($session->getName(), $session->getValue())
        ->get(route('auth.github.callback', ['state' => 'forged', 'code' => 'x']))
        ->assertRedirect(CONSOLE_ORIGIN.'/auth/github/callback?error=github_invalid_state');

    expect($query['state'])->toBeString();
});
