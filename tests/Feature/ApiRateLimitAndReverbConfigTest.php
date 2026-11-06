<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('logged-in users and guests have separate API budgets', function (): void {
    config()->set('sakala.rate_limits.api', 3);
    config()->set('sakala.rate_limits.api_guest', 1);

    $this->getJson('/api/v1')->assertOk();
    $this->getJson('/api/v1')->assertTooManyRequests();

    $this->actingAs(User::factory()->create(), 'web');

    foreach ([1, 2, 3] as $ignored) {
        $this->getJson(route('api.v1.auth.user'))->assertOk();
    }

    $this->getJson(route('api.v1.auth.user'))->assertTooManyRequests();
});

test('raising the API limit leaves login throttled by its own limiter', function (): void {
    config()->set('sakala.rate_limits.api', 1000);
    config()->set('sakala.rate_limits.api_guest', 1000);
    config()->set('sakala.rate_limits.login', 1);

    $payload = ['email' => 'nobody@example.test', 'password' => 'wrong'];

    $this->postJson(route('api.v1.auth.login'), $payload)->assertUnauthorized();
    $this->postJson(route('api.v1.auth.login'), $payload)->assertTooManyRequests();
});

function reverbOrigins(string $value): array
{
    $previous = [$_ENV['REVERB_ALLOWED_ORIGINS'] ?? null, $_SERVER['REVERB_ALLOWED_ORIGINS'] ?? null];
    $_ENV['REVERB_ALLOWED_ORIGINS'] = $_SERVER['REVERB_ALLOWED_ORIGINS'] = $value;

    try {
        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
    } finally {
        [$env, $server] = $previous;
        unset($_ENV['REVERB_ALLOWED_ORIGINS'], $_SERVER['REVERB_ALLOWED_ORIGINS']);

        if ($env !== null) {
            $_ENV['REVERB_ALLOWED_ORIGINS'] = $env;
        }

        if ($server !== null) {
            $_SERVER['REVERB_ALLOWED_ORIGINS'] = $server;
        }
    }
}

test('Reverb origins are normalised to the hostnames it compares', function (string $value, array $expected): void {
    expect(reverbOrigins($value))->toBe($expected);
})->with([
    'urls from the old example' => ['http://app.sakala.localhost:5173,http://localhost:5173', ['app.sakala.localhost', 'localhost']],
    'hostnames stay' => ['app.staging.sakala.dev,localhost,127.0.0.1', ['app.staging.sakala.dev', 'localhost', '127.0.0.1']],
    'host with port and spaces' => [' localhost:5173 , HTTPS://App.Example.com/path ', ['localhost', 'app.example.com']],
    'duplicates collapse' => ['http://localhost:5173,http://localhost:3000', ['localhost']],
    'wildcards stay' => ['*', ['*']],
    'subdomain wildcard' => ['*.sakala.dev', ['*.sakala.dev']],
    'ipv6' => ['http://[::1]:5173', ['[::1]']],
]);

test('the documented example file passes the origin check Reverb runs', function (): void {
    preg_match('/^REVERB_ALLOWED_ORIGINS=(.*)$/m', (string) file_get_contents(base_path('.env.example')), $match);

    expect($match[1])->not->toContain('://')
        ->and(reverbOrigins($match[1]))->toContain('localhost', 'app.sakala.localhost');
});
