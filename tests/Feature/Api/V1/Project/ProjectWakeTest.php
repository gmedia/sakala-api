<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestingPreventRequestForgery;

uses(RefreshDatabase::class);

function wakeCommands(): Builder
{
    return AgentCommand::query()->where('type', AgentCommandType::WakeProject);
}

function publicWake(Project|string $project): TestResponse
{
    $slug = $project instanceof Project ? $project->slug : $project;

    return test()->postJson("/api/v1/public/projects/{$slug}/wake");
}

// ─── Public ──────────────────────────────────────────────────────────────────

test('a visitor wakes a sleeping project once, however often the page asks', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);

    publicWake($project)->assertStatus(202)->assertNoContent(202);
    publicWake($project)->assertStatus(202);

    $wake = wakeCommands()->sole();

    expect($wake->project_id)->toBe($project->id)
        ->and($wake->deployment_id)->toBe($deployment->id)
        ->and($wake->agent_node_id)->toBe($node->id)
        ->and($wake->status)->toBe(AgentCommandStatus::Pending)
        ->and($wake->payload)->toBe([])
        ->and($wake->request_context)->toMatchArray(['source' => 'public', 'actor_type' => 'system', 'actor_id' => 'public-wake'])
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Sleeping);

    $audit = AuditEvent::query()->where('action', 'project.wake_requested')->sole();

    expect($audit->subject_id)->toBe($project->id)
        ->and($audit->actor_type)->toBe('system')
        ->and($audit->ip_address)->toBeNull()
        ->and($audit->user_agent)->toBeNull()
        ->and($audit->metadata)->toMatchArray(['source' => 'public', 'command_id' => $wake->id]);
});

test('the node receives the wake for the deployment it keeps', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = sleepingIdleProject($node);

    publicWake($project)->assertStatus(202);

    $this->withHeaders(idleSleepHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'WakeProject')
        ->assertJsonPath('data.0.deployment_id', $deployment->id);
});

test('a finished wake lets the next sleep be woken again', function (AgentCommandStatus $status): void {
    $node = idleSleepNode();
    [$project] = sleepingIdleProject($node);

    publicWake($project)->assertStatus(202);
    wakeCommands()->sole()->update(['status' => $status]);
    publicWake($project)->assertStatus(202);

    expect(wakeCommands()->count())->toBe(2);
})->with([AgentCommandStatus::Failed, AgentCommandStatus::Succeeded, AgentCommandStatus::Expired]);

test('the answer is the same 202 for anything that cannot be woken, and nothing is queued', function (callable $setup): void {
    $node = idleSleepNode();
    $slug = $setup($node);

    publicWake($slug)->assertStatus(202)->assertNoContent(202);

    expect(wakeCommands()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'project.wake_requested')->count())->toBe(0);
})->with([
    'unknown slug' => [fn () => 'tidak-ada'],
    'running' => [fn ($node) => idleSleepProject($node)[0]->slug],
    'stopped' => [fn ($node) => idleSleepProject($node, ['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null])[0]->slug],
    'crashed' => [fn ($node) => idleSleepProject($node, ['runtime_status' => RuntimeStatus::Crashed])[0]->slug],
    'sleeping but suspended' => [fn ($node) => sleepingIdleProject($node, ['status' => ProjectStatus::Suspended])[0]->slug],
    'sleeping but deleted' => [function ($node) {
        [$project] = sleepingIdleProject($node);
        $project->delete();

        return $project->slug;
    }],
    'sleeping without a deployment' => [function ($node) {
        [$project] = sleepingIdleProject($node);
        $project->update(['active_deployment_id' => null]);

        return $project->slug;
    }],
]);

test('the suspension is never revealed by the answer', function (): void {
    $node = idleSleepNode();
    [$suspended] = sleepingIdleProject($node, ['status' => ProjectStatus::Suspended]);
    [$running] = idleSleepProject($node);

    $a = publicWake($suspended);
    $b = publicWake($running);

    expect($a->status())->toBe($b->status())
        ->and($a->getContent())->toBe('')
        ->and($b->getContent())->toBe('');
});

test('a simple cross-origin POST works without a body, a session, or a CSRF token', function (): void {
    config()->set('sanctum.middleware.validate_csrf_token', TestingPreventRequestForgery::class);
    config()->set('sanctum.stateful', ['app.sakala.localhost:5173']);
    $node = idleSleepNode();
    [$project] = sleepingIdleProject($node);

    // What `fetch(url, {method: 'POST', mode: 'no-cors'})` sends from the
    // project domain; the console origin must not make it stateful either.
    foreach (['https://'.$project->default_domain, 'http://app.sakala.localhost:5173'] as $origin) {
        $response = $this->call('POST', "/api/v1/public/projects/{$project->slug}/wake", server: [
            'HTTP_ORIGIN' => $origin,
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
        ]);

        $response->assertStatus(202)->assertCookieMissing((string) config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
    }

    expect(wakeCommands()->count())->toBe(1);
});

test('a visitor is throttled per project', function (): void {
    config(['sakala.rate_limits.project_wake' => 2]);
    $node = idleSleepNode();
    [$project] = sleepingIdleProject($node);
    [$other] = sleepingIdleProject($node);

    publicWake($project)->assertStatus(202);
    publicWake($project)->assertStatus(202);
    publicWake($project)->assertStatus(429);

    // Another project keeps its own budget.
    publicWake($other)->assertStatus(202);
});

test('a slug that cannot be a project slug is not routed', function (): void {
    $this->postJson('/api/v1/public/projects/Not_A_Slug/wake')->assertNotFound();
});

test('a full workspace refuses the wake without telling a visitor its usage', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create(['max_active_containers' => 1])->id]);
    [$project] = sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);
    idleSleepProject($node, ['workspace_id' => $team->id], owner: $owner);

    publicWake($project)
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_WAKE_LIMIT_EXCEEDED')
        ->assertJsonMissingPath('limit')
        ->assertJsonMissingPath('current');

    expect(wakeCommands()->count())->toBe(0);
});

test('other sleeping projects do not count against the wake', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create(['max_active_containers' => 1])->id]);
    [$project] = sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);
    sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);

    publicWake($project)->assertStatus(202);

    expect(wakeCommands()->count())->toBe(1);
});

// ─── Console ─────────────────────────────────────────────────────────────────

test('an owner or member wakes a sleeping project from the console', function (WorkspaceRole $role): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]]);
    [$project, $deployment] = sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);
    $actor = $role === WorkspaceRole::Owner ? $owner : $member;

    $this->actingAs($actor, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/wake")
        ->assertStatus(202)
        ->assertJsonPath('data.id', $project->id)
        ->assertJsonPath('data.runtime_status', 'sleeping');

    // Pressing the button twice queues one wake.
    $this->actingAs($actor, 'web')->postJson("/api/v1/app/projects/{$project->id}/wake")->assertStatus(202);

    $wake = wakeCommands()->sole();

    expect($wake->deployment_id)->toBe($deployment->id)
        ->and($wake->request_context)->toMatchArray(['source' => 'console', 'actor_type' => User::class, 'actor_id' => (string) $actor->id])
        ->and(AuditEvent::query()->where('action', 'project.wake_requested')->sole()->actor_id)->toBe((string) $actor->id);
})->with([WorkspaceRole::Owner, WorkspaceRole::Member]);

test('viewers, outsiders, and guests cannot wake a project', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = teamWorkspace($owner, [[$viewer, WorkspaceRole::Viewer]]);
    [$project] = sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);

    $this->actingAs($viewer, 'web')->postJson("/api/v1/app/projects/{$project->id}/wake")->assertForbidden();
    $this->actingAs(User::factory()->create(), 'web')->postJson("/api/v1/app/projects/{$project->id}/wake")->assertForbidden();

    auth()->guard('web')->logout();
    $this->postJson("/api/v1/app/projects/{$project->id}/wake")->assertUnauthorized();

    expect(wakeCommands()->count())->toBe(0);
});

test('a suspended project tells its members why it cannot be woken', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project] = sleepingIdleProject($node, ['status' => ProjectStatus::Suspended], owner: $owner);

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/wake")
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_SUSPENDED');

    // Admins pass the policy but the suspension still holds.
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/wake")
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_SUSPENDED');

    expect(wakeCommands()->count())->toBe(0);
});

test('a project that is not sleeping answers 409 with its runtime status', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project] = idleSleepProject($node, owner: $owner);

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/wake")
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_NOT_SLEEPING')
        ->assertJsonPath('runtime_status', 'running');
});

test('a full workspace refuses the console wake with its usage', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create(['max_active_containers' => 1])->id]);
    [$project] = sleepingIdleProject($node, ['workspace_id' => $team->id], owner: $owner);
    idleSleepProject($node, ['workspace_id' => $team->id], owner: $owner);

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/wake")
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_WAKE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 1)
        ->assertJsonPath('current', 1)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'belum bisa dibangunkan'));

    expect(wakeCommands()->count())->toBe(0);
});
