<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function idleSleepProfileFields(array $overrides = []): array
{
    return [
        'name' => 'Kelas',
        'max_projects' => 5,
        'max_active_containers' => 3,
        'max_active_deployments' => 2,
        'max_memory_mb' => 512,
        'max_cpu_millis' => 1000,
        'reason' => 'Kelas baru',
        ...$overrides,
    ];
}

/** An idle running project in a team workspace under the given profile, or none. */
function idleProjectUnder(AgentNode $node, ?LimitProfile $profile): Project
{
    $owner = User::factory()->create();
    $workspace = teamWorkspace($owner, attributes: ['limit_profile_id' => $profile?->id]);
    [$project] = idleSleepProject($node, ['workspace_id' => $workspace->id, 'last_request_at' => now()->subHours(3)], owner: $owner);

    return $project;
}

function sleptProjectIds(): array
{
    return AgentCommand::query()->where('type', AgentCommandType::SleepProject)->pluck('project_id')->sort()->values()->all();
}

test('the window follows the profile, and null or no profile follows the global setting', function (?int $profileValue, int $global, int $expected): void {
    config(['sakala.project.idle_sleep_minutes' => $global]);
    $profile = LimitProfile::factory()->create(['idle_sleep_minutes' => $profileValue]);
    $service = app(PilotRuntimeLimitService::class);

    expect($service->idleSleepMinutesFor($profile))->toBe($expected)
        ->and($service->limitsFor(Workspace::factory()->withProfile($profile)->create())->idle_sleep_minutes)->toBe($expected)
        ->and($service->limitsFor(Workspace::factory()->create())->idle_sleep_minutes)->toBe($global)
        ->and($service->idleSleepMinutesFor(null))->toBe($global);
})->with([
    'inherit' => [null, 30, 30],
    'never' => [0, 30, 0],
    'own window' => [90, 30, 90],
    'own window while the global one is off' => [15, 0, 15],
]);

test('the sweep skips a workspace that never sleeps and sleeps the others', function (): void {
    config(['sakala.project.idle_sleep_minutes' => 30]);
    $node = idleSleepNode();
    $never = idleProjectUnder($node, LimitProfile::factory()->create(['idle_sleep_minutes' => 0]));
    $inherits = idleProjectUnder($node, LimitProfile::factory()->create(['idle_sleep_minutes' => null]));
    $default = idleProjectUnder($node, null);
    $longer = idleProjectUnder($node, LimitProfile::factory()->create(['idle_sleep_minutes' => 240]));

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 2 idle project(s) to sleep.');

    expect(sleptProjectIds())->toBe(collect([$inherits->id, $default->id])->sort()->values()->all())
        ->and(AgentCommand::query()->where('project_id', $never->id)->exists())->toBeFalse()
        ->and(AgentCommand::query()->where('project_id', $longer->id)->exists())->toBeFalse();

    // Past the profile's own window its project sleeps too.
    $this->travel(181)->minutes();
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');

    expect(AgentCommand::query()->where('project_id', $longer->id)->sole()->isIdleSleep())->toBeTrue()
        ->and(AuditEvent::query()->where('subject_id', $longer->id)->where('action', 'project.idle_sleep_requested')->sole()->metadata['idle_sleep_minutes'])->toBe(240);
});

test('with the global setting off, only workspaces that opt in sleep', function (): void {
    config(['sakala.project.idle_sleep_minutes' => 0]);
    $node = idleSleepNode();
    $optedIn = idleProjectUnder($node, LimitProfile::factory()->create(['idle_sleep_minutes' => 45]));
    idleProjectUnder($node, LimitProfile::factory()->create(['idle_sleep_minutes' => null]));
    idleProjectUnder($node, null);

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');

    expect(sleptProjectIds())->toBe([$optedIn->id]);
});

test('with the global setting off and no profile opting in, the sweep is off', function (): void {
    config(['sakala.project.idle_sleep_minutes' => 0]);
    $node = idleSleepNode();
    idleProjectUnder($node, LimitProfile::factory()->create(['idle_sleep_minutes' => 0]));
    idleProjectUnder($node, null);

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Idle sleep is off');

    expect(sleptProjectIds())->toBe([]);
});

test('admins set, change, and clear the idle window of a profile, with an audit trail', function (): void {
    $admin = adminApiAdmin();

    $id = $this->actingAs($admin, 'web')
        ->postJson('/api/v1/admin/limit-profiles', idleSleepProfileFields(['idle_sleep_minutes' => 45]))
        ->assertCreated()
        ->assertJsonPath('data.idle_sleep_minutes', 45)
        ->json('data.id');

    $this->actingAs($admin, 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$id}", ['idle_sleep_minutes' => 0, 'reason' => 'Demo kelas'])
        ->assertOk()
        ->assertJsonPath('data.idle_sleep_minutes', 0);

    $this->actingAs($admin, 'web')
        ->patchJson("/api/v1/admin/limit-profiles/{$id}", ['idle_sleep_minutes' => null, 'reason' => 'Ikut global'])
        ->assertOk()
        ->assertJsonPath('data.idle_sleep_minutes', null);

    $updates = AuditEvent::query()->where('action', 'limit_profile.updated')->orderBy('id')->pluck('metadata')->all();

    expect(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->metadata['values']['idle_sleep_minutes'])->toBe(45)
        ->and($updates[0]['from'])->toBe(['idle_sleep_minutes' => 45])
        ->and($updates[0]['to'])->toBe(['idle_sleep_minutes' => 0])
        ->and($updates[1]['to'])->toBe(['idle_sleep_minutes' => null]);
});

test('a profile created without an idle window follows the global setting', function (): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', idleSleepProfileFields())
        ->assertCreated()
        ->assertJsonPath('data.idle_sleep_minutes', null);
});

test('the idle window must be a whole number of minutes up to a week', function (mixed $value): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', idleSleepProfileFields(['idle_sleep_minutes' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['idle_sleep_minutes']);
})->with([
    'negative' => [-1],
    'over a week' => [10081],
    'fraction' => [1.5],
    'text' => ['thirty'],
]);

test('members and admins see the effective idle window of a workspace', function (): void {
    config(['sakala.project.idle_sleep_minutes' => 30]);
    $owner = User::factory()->create();
    $profile = LimitProfile::factory()->create(['idle_sleep_minutes' => 0]);
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => $profile->id]);
    $personal = $owner->personalWorkspace()->firstOrFail();

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/pilot-limits?workspace_id={$team->id}")
        ->assertOk()
        ->assertJsonPath('data.project_idle_sleep_minutes', 0);

    $this->actingAs($owner, 'web')
        ->getJson("/api/v1/app/pilot-limits?workspace_id={$personal->id}")
        ->assertJsonPath('data.project_idle_sleep_minutes', 30);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson("/api/v1/admin/workspaces/{$team->id}")
        ->assertJsonPath('data.quota.limits.idle_sleep_minutes', 0);
});
