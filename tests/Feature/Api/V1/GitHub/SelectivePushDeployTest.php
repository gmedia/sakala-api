<?php

declare(strict_types=1);

use App\Data\GitHub\SkippedPushData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\GithubInstallationStatus;
use App\Enums\PushSkipReason;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use App\Support\GitHub\PushChangedPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

const MONOREPO_SHA = '1234567890abcdef1234567890abcdef12345678';
const MONOREPO_DELIVERY = '6f1c2a3e-58cc-4372-a567-0e02b2c3d479';

beforeEach(function (): void {
    config()->set('services.github_app.webhook_secret', 'webhook-secret');
    // Several projects of one workspace deploy from one push here; the running limits are not under test.
    config()->set('sakala.pilot_limits.max_active_deployments_per_user', 10);
    config()->set('sakala.pilot_limits.max_active_deployments_per_project', 5);
    Http::fake(['api.github.com/repositories/777' => Http::response(['id' => 777])]);
    Http::preventStrayRequests();
});

function monorepoOwner(): User
{
    $owner = User::factory()->create(['role' => UserRole::User]);
    OAuthAccount::factory()->for($owner)->create(['provider' => 'github', 'access_token' => 'owner-token']);

    return $owner;
}

/** A project of the owner's personal workspace on repository 777, branch main, with a successful deployment unless told otherwise. */
function monorepoProject(User $owner, ?string $root, array $attributes = [], bool $deployed = true): Project
{
    $installation = GithubInstallation::query()->firstOrCreate(['github_installation_id' => 42], [
        'account_id' => 100,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);

    $project = Project::factory()->for($owner)->create([
        'github_installation_id' => $installation->id,
        'github_repository_id' => 777,
        'branch' => 'main',
        'auto_deploy' => true,
        'root_directory' => $root,
        ...$attributes,
    ]);

    if ($deployed) {
        Deployment::factory()->for($project)->create([
            'requested_by' => $owner->id,
            'status' => DeploymentStatus::Succeeded,
            'sequence' => 1,
            'branch' => 'main',
            'created_at' => now()->subHours(2),
        ]);
    }

    return $project;
}

/** @param  list<string>  $modified */
function monorepoPayload(array $modified, array $overrides = []): array
{
    return [
        'ref' => 'refs/heads/main',
        'before' => str_repeat('9', 40),
        'after' => MONOREPO_SHA,
        'created' => false,
        'deleted' => false,
        'forced' => false,
        'repository' => ['id' => 777],
        'installation' => ['id' => 42],
        'head_commit' => ['id' => MONOREPO_SHA, 'message' => "feat(api): tambah endpoint\n\nbadan commit"],
        'commits' => [['id' => MONOREPO_SHA, 'added' => [], 'removed' => [], 'modified' => $modified]],
        ...$overrides,
    ];
}

function sendMonorepoPush(object $test, array $payload, string $delivery = MONOREPO_DELIVERY): mixed
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

function webhookDeployments(Project $project): int
{
    return Deployment::query()->whereBelongsTo($project)->where('trigger', DeploymentTrigger::Webhook)->count();
}

function skippedPushes(?Project $project = null): int
{
    return AuditEvent::query()
        ->where('action', SkippedPushData::AUDIT_ACTION)
        ->when($project !== null, fn ($query) => $query->where('subject_id', $project?->id))
        ->count();
}

/** @return array{User, Project, Project, Project} */
function threeAppMonorepo(): array
{
    $owner = monorepoOwner();

    return [
        $owner,
        monorepoProject($owner, 'apps/web'),
        monorepoProject($owner, 'apps/api'),
        monorepoProject($owner, 'apps/landing'),
    ];
}

test('a change in one app deploys only that app and records the others as skipped', function (): void {
    [, $web, $api, $landing] = threeAppMonorepo();

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php', 'apps/api/app/Http/Kernel.php']))->assertOk();

    expect(webhookDeployments($api))->toBe(1)
        ->and(webhookDeployments($web))->toBe(0)
        ->and(webhookDeployments($landing))->toBe(0)
        ->and(skippedPushes($api))->toBe(0);

    $skip = AuditEvent::query()->where('action', SkippedPushData::AUDIT_ACTION)->where('subject_id', $web->id)->sole();
    $baseline = Deployment::query()->whereBelongsTo($web)->sole();

    expect($skip->actor_type)->toBe('github_app')
        ->and($skip->subject_type)->toBe(Project::class)
        ->and($skip->metadata)->toBe([
            'delivery_id' => MONOREPO_DELIVERY,
            'reason' => 'no_changes_in_root_directory',
            'commit_sha' => MONOREPO_SHA,
            'commit_message' => 'feat(api): tambah endpoint',
            'branch' => 'main',
            'root_directory' => 'apps/web',
            'changed_path_count' => 2,
            'example_paths' => ['apps/api/app/Http/Kernel.php', 'apps/api/routes.php'],
            'after_deployment_id' => $baseline->id,
        ])
        ->and(skippedPushes($landing))->toBe(1);
});

test('a shared file deploys every app', function (array $files): void {
    [, $web, $api, $landing] = threeAppMonorepo();

    sendMonorepoPush($this, monorepoPayload($files))->assertOk();

    expect(webhookDeployments($web))->toBe(1)
        ->and(webhookDeployments($api))->toBe(1)
        ->and(webhookDeployments($landing))->toBe(1)
        ->and(skippedPushes())->toBe(0);
})->with([
    'lockfile' => [['pnpm-lock.yaml']],
    'shared package' => [['packages/ui/src/button.tsx']],
    'root README' => [['README.md']],
    'a folder that only looks like an app' => [['apps/webhooks/handler.ts']],
    'app change plus a shared file' => [['apps/api/routes.php', 'turbo.json']],
]);

test('files across commits of one push are combined', function (): void {
    [, $web, $api, $landing] = threeAppMonorepo();

    sendMonorepoPush($this, monorepoPayload([], ['commits' => [
        ['id' => 'a', 'added' => ['apps/web/new.ts'], 'removed' => [], 'modified' => []],
        ['id' => 'b', 'added' => [], 'removed' => ['apps/landing/old.html'], 'modified' => []],
    ]]))->assertOk();

    expect(webhookDeployments($web))->toBe(1)
        ->and(webhookDeployments($landing))->toBe(1)
        ->and(webhookDeployments($api))->toBe(0)
        ->and(AuditEvent::query()->where('subject_id', $api->id)->where('action', SkippedPushData::AUDIT_ACTION)->sole()->metadata['changed_path_count'])->toBe(2);
});

test('a path belongs to the most specific root of nested projects', function (): void {
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web');
    $admin = monorepoProject($owner, 'apps/web/admin');

    sendMonorepoPush($this, monorepoPayload(['apps/web/admin/pages/index.tsx']))->assertOk();

    expect(webhookDeployments($admin))->toBe(1)
        ->and(webhookDeployments($web))->toBe(0)
        ->and(skippedPushes($web))->toBe(1);

    sendMonorepoPush($this, monorepoPayload(['apps/web/src/app.tsx']), '7a1c2a3e-58cc-4372-a567-0e02b2c3d479')->assertOk();

    expect(webhookDeployments($web))->toBe(1)
        ->and(skippedPushes($admin))->toBe(1);
});

test('a project without a root directory deploys on every push', function (?string $root): void {
    $owner = monorepoOwner();
    $whole = monorepoProject($owner, $root);
    $api = monorepoProject($owner, 'apps/api');
    $web = monorepoProject($owner, 'apps/web');

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();

    expect(webhookDeployments($whole))->toBe(1)
        ->and(webhookDeployments($api))->toBe(1)
        ->and(webhookDeployments($web))->toBe(0);
})->with([
    'null' => [null],
    'dot' => ['.'],
]);

test('a project without a root directory owns no folder, so shared files still reach the apps', function (): void {
    $owner = monorepoOwner();
    monorepoProject($owner, null);
    $web = monorepoProject($owner, 'apps/web');

    sendMonorepoPush($this, monorepoPayload(['pnpm-lock.yaml']))->assertOk();

    expect(webhookDeployments($web))->toBe(1);
});

test('a folder belongs to its project even when that project deploys by hand', function (): void {
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web');
    $api = monorepoProject($owner, 'apps/api', ['auto_deploy' => false]);

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();

    expect(webhookDeployments($web))->toBe(0)
        ->and(webhookDeployments($api))->toBe(0)
        ->and(skippedPushes($web))->toBe(1)
        ->and(skippedPushes($api))->toBe(0);
});

test('projects of another workspace or another branch own no folder here', function (): void {
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web');
    monorepoProject($owner, 'apps/docs', ['branch' => 'develop']);
    monorepoProject(monorepoOwner(), 'apps/api', ['auto_deploy' => false]);

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php', 'apps/docs/index.md']))->assertOk();

    expect(webhookDeployments($web))->toBe(1);
});

test('a deleted project no longer owns its folder', function (): void {
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web');
    monorepoProject($owner, 'apps/api')->delete();

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();

    expect(webhookDeployments($web))->toBe(1);
});

test('a payload that may not list every changed file deploys every app', function (array $overrides): void {
    [, $web, $api, $landing] = threeAppMonorepo();

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php'], $overrides))->assertOk();

    expect(webhookDeployments($web))->toBe(1)
        ->and(webhookDeployments($api))->toBe(1)
        ->and(webhookDeployments($landing))->toBe(1)
        ->and(skippedPushes())->toBe(0);
})->with([
    'force push' => [['forced' => true]],
    'new branch' => [['created' => true, 'before' => str_repeat('0', 40)]],
    'commits missing' => [['commits' => null]],
    'commits empty with a head commit' => [['commits' => []]],
    'commit without file lists' => [['commits' => [['id' => 'a', 'message' => 'x']]]],
    'commit list at the GitHub cap' => [['commits' => array_fill(0, PushChangedPaths::MAX_COMMITS, ['id' => 'a', 'added' => [], 'removed' => [], 'modified' => ['apps/api/x']])]],
    'commit at the file cap' => [['commits' => [['id' => 'a', 'added' => [], 'removed' => [], 'modified' => array_map(fn (int $i): string => "apps/api/f{$i}", range(1, PushChangedPaths::MAX_FILES_PER_COMMIT))]]]],
    'an empty commit, pushed to trigger a deploy' => [['commits' => [['id' => 'a', 'added' => [], 'removed' => [], 'modified' => []]]]],
    'file list not strings' => [['commits' => [['id' => 'a', 'added' => [['path' => 'x']], 'removed' => [], 'modified' => []]]]],
]);

test('a project whose running version may lack an earlier change deploys', function (Closure $arrange): void {
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web', deployed: false);
    monorepoProject($owner, 'apps/api');
    $arrange($web);

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();

    expect(webhookDeployments($web))->toBe(1)
        ->and(skippedPushes($web))->toBe(0);
})->with([
    'never deployed' => [fn (Project $web) => null],
    'never succeeded, one still running' => [fn (Project $web) => Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Building, 'sequence' => 1])],
    'latest failed' => [function (Project $web): void {
        Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1, 'created_at' => now()->subHours(3)]);
        Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Failed, 'sequence' => 2, 'created_at' => now()->subHour()]);
    }],
    'latest cancelled' => [function (Project $web): void {
        Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1, 'created_at' => now()->subHours(3)]);
        Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Cancelled, 'sequence' => 2, 'created_at' => now()->subHour()]);
    }],
    'latest of another branch' => [fn (Project $web) => Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1, 'branch' => 'develop'])],
    'a push skipped for a limit since then' => [function (Project $web): void {
        Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1, 'created_at' => now()->subHours(3)]);
        AuditEvent::create([
            'actor_type' => 'github_app',
            'action' => 'project.auto_deploy_skipped',
            'subject_type' => Project::class,
            'subject_id' => $web->id,
            'metadata' => ['reason' => 'build_rate_limit', 'commit_sha' => str_repeat('b', 40)],
        ]);
    }],
]);

test('a push after a successful deployment that is still followed by a running one is skipped', function (): void {
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web');
    monorepoProject($owner, 'apps/api');
    $running = Deployment::factory()->for($web)->create(['status' => DeploymentStatus::Building, 'sequence' => 2, 'created_at' => now()->subMinute()]);

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();

    expect(webhookDeployments($web))->toBe(0)
        ->and(AuditEvent::query()->where('subject_id', $web->id)->where('action', SkippedPushData::AUDIT_ACTION)->sole()->metadata['after_deployment_id'])->toBe($running->id);
});

test('a redelivery records a skipped push only once', function (): void {
    [, $web, $api] = threeAppMonorepo();

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk()->assertJsonPath('duplicate', false);
    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk()->assertJsonPath('duplicate', true);

    expect(skippedPushes($web))->toBe(1)
        ->and(webhookDeployments($api))->toBe(1);
});

test('a skipped push notifies no one, emails no one, and leaves the deployments alone', function (): void {
    Notification::fake();
    Mail::fake();
    config()->set('sakala.email_delivery_enabled', true);
    $owner = monorepoOwner();
    $web = monorepoProject($owner, 'apps/web');
    monorepoProject($owner, 'apps/api', ['auto_deploy' => false]);
    $before = $web->fresh()->latestDeployment;

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();

    expect(skippedPushes($web))->toBe(1)
        ->and(Deployment::query()->count())->toBe(2)
        ->and($web->fresh()->latestDeployment?->id)->toBe($before?->id)
        ->and(AuditEvent::query()->where('action', 'project.auto_deploy_skipped')->exists())->toBeFalse();
    Notification::assertNothingSent();
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

test('skipped pushes do not count against the build rate limit', function (): void {
    config()->set('sakala.project.max_builds_per_hour', 2);
    [, $web, $api, $landing] = threeAppMonorepo();

    sendMonorepoPush($this, monorepoPayload(['apps/api/routes.php']))->assertOk();
    sendMonorepoPush($this, monorepoPayload(['apps/web/src/app.tsx']), '8b1c2a3e-58cc-4372-a567-0e02b2c3d479')->assertOk();

    expect(webhookDeployments($api))->toBe(1)
        ->and(webhookDeployments($web))->toBe(1)
        ->and(webhookDeployments($landing))->toBe(0)
        ->and(skippedPushes($landing))->toBe(2)
        ->and(AuditEvent::query()->where('action', 'project.auto_deploy_skipped')->exists())->toBeFalse();
});

test('members and viewers read skipped pushes newest first, outsiders do not', function (): void {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    $team = teamWorkspace($owner, [[$viewer, WorkspaceRole::Viewer]]);
    $project = Project::factory()->inWorkspace($team)->create(['root_directory' => 'apps/web']);
    $deployment = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1]);

    foreach (['2026-11-18 08:00:00', '2026-11-18 09:00:00'] as $index => $time) {
        $this->travelTo($time);
        AuditEvent::create([
            'actor_type' => 'github_app',
            'action' => SkippedPushData::AUDIT_ACTION,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'metadata' => SkippedPushData::fromPush(
                deliveryId: "00000000-0000-4000-8000-00000000000{$index}",
                reason: PushSkipReason::NoChangesInRootDirectory,
                commitSha: str_repeat((string) $index, 40),
                commitMessage: "commit {$index}",
                branch: 'main',
                rootDirectory: 'apps/web',
                changedPaths: ['apps/api/a', 'apps/api/b', 'apps/api/c', 'apps/api/d', 'apps/api/e', 'apps/api/f'],
                afterDeploymentId: $deployment->id,
            )->toMetadata(),
        ]);
    }
    // Other actions and other projects are not listed.
    AuditEvent::create(['action' => 'project.auto_deploy_skipped', 'subject_type' => Project::class, 'subject_id' => $project->id, 'metadata' => ['reason' => 'build_rate_limit']]);
    $this->travelBack();

    $url = "/api/v1/app/projects/{$project->id}/skipped-pushes";

    $this->actingAs($viewer, 'web')->getJson($url)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0', [
            'delivery_id' => '00000000-0000-4000-8000-000000000001',
            'reason' => 'no_changes_in_root_directory',
            'commit_sha' => str_repeat('1', 40),
            'commit_message' => 'commit 1',
            'branch' => 'main',
            'root_directory' => 'apps/web',
            'changed_path_count' => 6,
            'example_paths' => ['apps/api/a', 'apps/api/b', 'apps/api/c', 'apps/api/d', 'apps/api/e'],
            'after_deployment_id' => $deployment->id,
            'created_at' => '2026-11-18T09:00:00+00:00',
        ])
        ->assertJsonPath('data.1.commit_message', 'commit 0');

    $this->actingAs($owner, 'web')->getJson($url.'?since=2026-11-18T08:30:00Z')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.commit_message', 'commit 1');

    $this->actingAs($owner, 'web')->getJson($url.'?per_page=1&page=2')
        ->assertOk()
        ->assertJsonPath('data.0.commit_message', 'commit 0')
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($owner, 'web')->getJson($url.'?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->actingAs($owner, 'web')->getJson($url.'?since=kemarin')->assertUnprocessable()->assertJsonValidationErrors('since');
    $this->actingAs($outsider, 'web')->getJson($url)->assertForbidden();
});

test('reading skipped pushes needs a session', function (): void {
    $project = Project::factory()->create();

    $this->getJson("/api/v1/app/projects/{$project->id}/skipped-pushes")->assertUnauthorized();
});
