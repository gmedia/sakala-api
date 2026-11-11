<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function valueUrl(Project $project, EnvironmentVariable $variable): string
{
    return route('api.v1.app.projects.environment-variables.value', [$project, $variable]);
}

test('revealing a secret records who, which project, and the variable name, never the value', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['key' => 'API_KEY', 'encrypted_value' => 'super-secret-value', 'is_secret' => true]);

    $this->actingAs($owner, 'web')
        ->getJson(valueUrl($project, $variable))
        ->assertOk()
        ->assertJsonPath('data.value', 'super-secret-value');

    $audit = AuditEvent::query()->where('action', 'environment_variable.value_revealed')->sole();

    expect((string) $audit->actor_id)->toBe((string) $owner->id)
        ->and($audit->subject_id)->toBe($project->id)
        ->and($audit->metadata['variable_name'])->toBe('API_KEY')
        ->and($audit->metadata['by_project_owner'])->toBeTrue()
        ->and($audit->ip_address)->toBeNull()
        ->and(json_encode($audit->getAttributes()))->not->toContain('super-secret-value')
        ->and(json_encode($audit->getAttributes()))->not->toContain($variable->getRawOriginal('encrypted_value'));
});

test('an admin reading someone else\'s secret is recorded with the address', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $project = Project::factory()->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['is_secret' => true]);

    $this->actingAs($admin, 'web')->getJson(valueUrl($project, $variable))->assertOk();

    $audit = AuditEvent::query()->where('action', 'environment_variable.value_revealed')->sole();

    expect((string) $audit->actor_id)->toBe((string) $admin->id)
        ->and($audit->metadata['by_project_owner'])->toBeFalse()
        ->and($audit->ip_address)->not->toBeNull();
});

test('a refused reveal records nothing and returns nothing', function (): void {
    $project = Project::factory()->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['is_secret' => true]);

    $this->getJson(valueUrl($project, $variable))->assertUnauthorized();
    $this->actingAs(User::factory()->create(), 'web')->getJson(valueUrl($project, $variable))->assertForbidden();

    expect(AuditEvent::query()->where('action', 'environment_variable.value_revealed')->count())->toBe(0);
});

test('a non-secret value is in the list already, so reading it is not recorded', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['is_secret' => false]);

    $this->actingAs($owner, 'web')->getJson(valueUrl($project, $variable))->assertOk();

    expect(AuditEvent::query()->where('action', 'environment_variable.value_revealed')->count())->toBe(0);
});

test('no value leaves when the audit row cannot be written', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['encrypted_value' => 'super-secret-value', 'is_secret' => true]);

    AuditEvent::creating(static fn () => throw new RuntimeException('audit store is down'));

    $response = $this->actingAs($owner, 'web')->getJson(valueUrl($project, $variable));

    expect($response->status())->toBe(500)
        ->and((string) $response->getContent())->not->toContain('super-secret-value');
});
