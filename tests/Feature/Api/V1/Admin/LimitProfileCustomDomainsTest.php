<?php

declare(strict_types=1);

use App\Models\LimitProfile;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function customDomainProfileFields(array $overrides = []): array
{
    return [
        'name' => 'Domain',
        'max_projects' => 5,
        'max_active_containers' => 10,
        'max_active_deployments' => 2,
        'max_memory_mb' => 512,
        'max_cpu_millis' => 1000,
        'reason' => 'Uji custom domain',
        ...$overrides,
    ];
}

test('admins set, clear, and bound max_custom_domains on a profile', function (): void {
    $this->actingAs(adminApiAdmin(), 'web');

    $id = $this->postJson('/api/v1/admin/limit-profiles', customDomainProfileFields(['max_custom_domains' => 5]))
        ->assertCreated()
        ->assertJsonPath('data.max_custom_domains', 5)
        ->json('data.id');

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_custom_domains' => 0, 'reason' => 'Tutup'])
        ->assertOk()
        ->assertJsonPath('data.max_custom_domains', 0);

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_custom_domains' => null, 'reason' => 'Ikut global'])
        ->assertOk()
        ->assertJsonPath('data.max_custom_domains', null);

    $this->postJson('/api/v1/admin/limit-profiles', customDomainProfileFields(['name' => 'Terlalu', 'max_custom_domains' => LimitProfile::MAX_CUSTOM_DOMAINS_CEILING + 1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_custom_domains');
});

test('the effective value follows the profile, else the global setting, else one', function (): void {
    $limits = app(PilotRuntimeLimitService::class);

    expect($limits->maxCustomDomainsFor(null))->toBe(PilotRuntimeLimitService::DEFAULT_MAX_CUSTOM_DOMAINS)
        ->and($limits->maxCustomDomainsFor(LimitProfile::factory()->make(['max_custom_domains' => 0])))->toBe(0)
        ->and($limits->maxCustomDomainsFor(LimitProfile::factory()->make(['max_custom_domains' => null])))->toBe(1);

    config(['sakala.custom_domains.default_max_count' => '']);
    expect($limits->maxCustomDomainsFor(null))->toBe(1);

    config(['sakala.custom_domains.default_max_count' => 4]);
    expect($limits->maxCustomDomainsFor(null))->toBe(4);
});
