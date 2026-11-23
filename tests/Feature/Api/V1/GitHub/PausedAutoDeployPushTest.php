<?php

declare(strict_types=1);

use App\Data\GitHub\SkippedPushData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\GithubInstallationStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const PAUSED_PUSH_SHA = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
const PAUSED_PUSH_DELIVERY = '0b5a6c1e-58cc-4372-a567-0e02b2c3d479';

beforeEach(function (): void {
    config()->set('services.github_app.webhook_secret', 'webhook-secret');
    config()->set('sakala.pilot_limits.max_active_deployments_per_user', 10);
    config()->set('sakala.pilot_limits.max_active_deployments_per_project', 5);
    Http::fake(['api.github.com/repositories/888' => Http::response(['id' => 888])]);
    Http::preventStrayRequests();
    Notification::fake();
    Mail::fake();
});

/**
 * A project of a personal workspace on repository 888, branch main, live with
 * one deployment. `$latest` is the trigger of a second, newest deployment.
 */
function pausedPushProject(?string $root = null, array $attributes = [], ?DeploymentTrigger $latest = null): Project
{
    $owner = User::factory()->create(['role' => UserRole::User]);
    OAuthAccount::factory()->for($owner)->create(['provider' => 'github', 'access_token' => 'owner-token']);

    $installation = GithubInstallation::query()->firstOrCreate(['github_installation_id' => 43], [
        'account_id' => 101,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);

    $project = Project::factory()->for($owner)->create([
        'github_installation_id' => $installation->id,
        'github_repository_id' => 888,
        'branch' => 'main',
        'auto_deploy' => true,
        'root_directory' => $root,
        ...$attributes,
    ]);

    $first = Deployment::factory()->for($project)->create([
        'requested_by' => $owner->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'branch' => 'main',
        'created_at' => now()->subHours(3),
    ]);

    if ($latest !== null) {
        Deployment::factory()->for($project)->create([
            'requested_by' => $owner->id,
            'status' => DeploymentStatus::Succeeded,
            'trigger' => $latest,
            'rollback_target_deployment_id' => $latest === DeploymentTrigger::Rollback ? $first->id : null,
            'sequence' => 2,
            'branch' => 'main',
            'created_at' => now()->subHours(2),
        ]);
    }

    return $project->fresh();
}

/** Pause auto-deploy the way a rollback leaves it. */
function pausedPushPause(Project $project): Project
{
    $project->update([
        'auto_deploy' => false,
        'auto_deploy_paused_at' => now()->subHour(),
        'auto_deploy_paused_by_deployment_id' => $project->latestDeployment()->value('id'),
    ]);

    return $project->fresh();
}

/** @param  list<string>  $modified */
function pausedPushPayload(array $modified = ['src/app.ts'], array $overrides = []): array
{
    return [
        'ref' => 'refs/heads/main',
        'before' => str_repeat('9', 40),
        'after' => PAUSED_PUSH_SHA,
        'created' => false,
        'deleted' => false,
        'forced' => false,
        'repository' => ['id' => 888],
        'installation' => ['id' => 43],
        'pusher' => ['name' => 'rani-dev', 'email' => 'rani@example.test'],
        'sender' => ['login' => 'rani-sender'],
        'head_commit' => ['id' => PAUSED_PUSH_SHA, 'message' => "fix: perbaiki login\n\nbadan"],
        'commits' => [['id' => PAUSED_PUSH_SHA, 'added' => [], 'removed' => [], 'modified' => $modified]],
        ...$overrides,
    ];
}

function sendPausedPush(object $test, array $payload, string $delivery = PAUSED_PUSH_DELIVERY): mixed
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', route('api.v1.webhooks.github'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GITHUB_DELIVERY' => $delivery,
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret'),
    ], $body);
}

function pausedPushWebhookDeployments(Project $project): int
{
    return Deployment::query()->whereBelongsTo($project)->where('trigger', DeploymentTrigger::Webhook)->count();
}

// ─── Held pushes ─────────────────────────────────────────────────────────────

test('a push to a paused project is held: recorded, not deployed, and nobody is notified', function (): void {
    $project = pausedPushPause(pausedPushProject('apps/web', latest: DeploymentTrigger::Rollback));
    $rollback = $project->latestDeployment()->firstOrFail();

    sendPausedPush($this, pausedPushPayload(['apps/web/src/login.ts', 'apps/web/README.md']))->assertOk();

    expect(pausedPushWebhookDeployments($project))->toBe(0);

    $skip = AuditEvent::query()->where('action', SkippedPushData::AUDIT_ACTION)->where('subject_id', $project->id)->sole();
    expect($skip->actor_type)->toBe('github_app')
        ->and($skip->metadata)->toBe([
            'delivery_id' => PAUSED_PUSH_DELIVERY,
            'reason' => 'auto_deploy_paused',
            'commit_sha' => PAUSED_PUSH_SHA,
            'commit_message' => 'fix: perbaiki login',
            'branch' => 'main',
            'root_directory' => 'apps/web',
            'changed_path_count' => 2,
            'example_paths' => ['apps/web/README.md', 'apps/web/src/login.ts'],
            'after_deployment_id' => $rollback->id,
        ])
        ->and(AuditEvent::query()->where('action', 'project.auto_deploy_skipped')->exists())->toBeFalse();

    Notification::assertNothingSent();
    Mail::assertNothingOutgoing();

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/skipped-pushes")
        ->assertOk()
        ->assertJsonPath('data.0.reason', 'auto_deploy_paused')
        ->assertJsonPath('data.0.after_deployment_id', $rollback->id);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.auto_deploy_pause.held_push_count', 1)
        ->assertJsonPath('data.auto_deploy_pause.deployment_id', $rollback->id)
        ->assertJsonPath('data.auto_deploy_pause.deployment_sequence', 2);
});

test('a held push without a file list or root directory still records the push', function (): void {
    $project = pausedPushPause(pausedPushProject(latest: DeploymentTrigger::Rollback));

    sendPausedPush($this, pausedPushPayload(overrides: ['forced' => true]))->assertOk();

    $skip = AuditEvent::query()->where('action', SkippedPushData::AUDIT_ACTION)->sole();
    expect($skip->metadata['reason'])->toBe('auto_deploy_paused')
        ->and($skip->metadata['root_directory'])->toBe('')
        ->and($skip->metadata['changed_path_count'])->toBe(0)
        ->and($skip->metadata['example_paths'])->toBe([])
        ->and(pausedPushWebhookDeployments($project))->toBe(0);
});

test('a redelivered held push is recorded once', function (): void {
    $project = pausedPushPause(pausedPushProject(latest: DeploymentTrigger::Rollback));

    sendPausedPush($this, pausedPushPayload())->assertOk();
    sendPausedPush($this, pausedPushPayload())->assertOk();

    expect(AuditEvent::query()->where('action', SkippedPushData::AUDIT_ACTION)->where('subject_id', $project->id)->count())->toBe(1)
        ->and(pausedPushWebhookDeployments($project))->toBe(0);
});

test('held pushes are counted from when the pause started', function (): void {
    $project = pausedPushPause(pausedPushProject(latest: DeploymentTrigger::Rollback));
    // A held push of an earlier pause does not count.
    AuditEvent::query()->forceCreate([
        'actor_type' => 'github_app',
        'action' => SkippedPushData::AUDIT_ACTION,
        'subject_type' => Project::class,
        'subject_id' => $project->id,
        'metadata' => ['reason' => 'auto_deploy_paused'],
        'created_at' => now()->subHours(5),
    ]);

    sendPausedPush($this, pausedPushPayload())->assertOk();
    sendPausedPush($this, pausedPushPayload(), '1b5a6c1e-58cc-4372-a567-0e02b2c3d479')->assertOk();

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.auto_deploy_pause.held_push_count', 2);
});

test('a project whose auto-deploy is simply off ignores pushes as before', function (): void {
    $project = pausedPushProject(attributes: ['auto_deploy' => false]);

    sendPausedPush($this, pausedPushPayload())->assertOk();

    expect(AuditEvent::query()->where('action', SkippedPushData::AUDIT_ACTION)->exists())->toBeFalse()
        ->and(pausedPushWebhookDeployments($project))->toBe(0);
});

// ─── Who pushed ──────────────────────────────────────────────────────────────

test('a push deployment records who pushed', function (array $overrides, ?string $expected): void {
    $project = pausedPushProject();

    sendPausedPush($this, pausedPushPayload(overrides: $overrides))->assertOk();

    $deployment = Deployment::query()->whereBelongsTo($project)->where('trigger', DeploymentTrigger::Webhook)->sole();
    expect($deployment->triggered_by_name)->toBe($expected);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}")
        ->assertJsonPath('data.triggered_by', ['kind' => 'github_push', 'name' => $expected, 'user_id' => null]);
})->with([
    'pusher name' => [[], 'rani-dev'],
    'pusher name trimmed' => [['pusher' => ['name' => '  rani-dev  ']], 'rani-dev'],
    'sender login without a pusher name' => [['pusher' => ['email' => 'x@example.test']], 'rani-sender'],
    'blank pusher name' => [['pusher' => ['name' => '   ']], 'rani-sender'],
    'neither' => [['pusher' => null, 'sender' => null], null],
    'too long' => [['pusher' => ['name' => str_repeat('a', 300)]], str_repeat('a', 255)],
]);

// ─── Monorepo baseline ───────────────────────────────────────────────────────

/** Two apps of one personal workspace; `$web` is live through `$webLatest`. @return array{Project, Project} */
function pausedPushMonorepo(?DeploymentTrigger $webLatest): array
{
    $web = pausedPushProject('apps/web', latest: $webLatest);
    $api = Project::factory()->inWorkspace($web->workspace, $web->user)->create([
        'github_installation_id' => $web->github_installation_id,
        'github_repository_id' => 888,
        'branch' => 'main',
        'auto_deploy' => true,
        'root_directory' => 'apps/api',
    ]);

    return [$web, $api];
}

test('after a rollback a push for another app still deploys this one', function (): void {
    [$web] = pausedPushMonorepo(DeploymentTrigger::Rollback);

    sendPausedPush($this, pausedPushPayload(['apps/api/routes.php']))->assertOk();

    expect(pausedPushWebhookDeployments($web))->toBe(1);
});

test('a push held since the latest deployment makes the next push deploy', function (): void {
    [$web] = pausedPushMonorepo(DeploymentTrigger::Manual);
    AuditEvent::query()->forceCreate([
        'actor_type' => 'github_app',
        'action' => SkippedPushData::AUDIT_ACTION,
        'subject_type' => Project::class,
        'subject_id' => $web->id,
        'metadata' => ['reason' => 'auto_deploy_paused'],
        'created_at' => now()->subMinutes(30),
    ]);

    sendPausedPush($this, pausedPushPayload(['apps/api/routes.php']))->assertOk();

    expect(pausedPushWebhookDeployments($web))->toBe(1);
});

test('without a rollback or held push a push for another app is skipped as before', function (): void {
    [$web] = pausedPushMonorepo(DeploymentTrigger::Manual);

    sendPausedPush($this, pausedPushPayload(['apps/api/routes.php']))->assertOk();

    expect(pausedPushWebhookDeployments($web))->toBe(0)
        ->and(AuditEvent::query()->where('subject_id', $web->id)->where('action', SkippedPushData::AUDIT_ACTION)->sole()->metadata['reason'])
        ->toBe('no_changes_in_root_directory');
});

test('a paused app is skipped for another app\'s push like any other and held otherwise', function (): void {
    [$web] = pausedPushMonorepo(DeploymentTrigger::Manual);
    pausedPushPause($web);

    sendPausedPush($this, pausedPushPayload(['apps/api/routes.php']))->assertOk();
    sendPausedPush($this, pausedPushPayload(['apps/web/page.tsx']), '2b5a6c1e-58cc-4372-a567-0e02b2c3d479')->assertOk();

    expect(AuditEvent::query()->where('subject_id', $web->id)->where('action', SkippedPushData::AUDIT_ACTION)->orderBy('id')->get()->pluck('metadata.reason')->all())
        ->toBe(['no_changes_in_root_directory', 'auto_deploy_paused'])
        ->and(pausedPushWebhookDeployments($web))->toBe(0);
});
