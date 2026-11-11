<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\GithubInstallationStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\GithubInstallation;
use App\Models\GithubWebhookDelivery;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const PUSH_SHA = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';
const PUSH_DELIVERY = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';

beforeEach(function (): void {
    config()->set('services.github_app.webhook_secret', 'webhook-secret');
    Http::fake(['api.github.com/repositories/777' => Http::response(['id' => 777])]);
    Http::preventStrayRequests();
});

function pushProject(array $attributes = [], GithubInstallationStatus $status = GithubInstallationStatus::Active): Project
{
    $installation = GithubInstallation::query()->firstOrCreate(['github_installation_id' => 42], [
        'account_id' => 100,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => $status,
    ]);

    $owner = User::factory()->create(['role' => UserRole::User]);
    OAuthAccount::factory()->for($owner)->create(['provider' => 'github', 'access_token' => 'owner-token']);

    return Project::factory()->for($owner)->create([
        'github_installation_id' => $installation->id,
        'github_repository_id' => 777,
        'branch' => 'main',
        'auto_deploy' => true,
        ...$attributes,
    ]);
}

function pushPayload(array $overrides = []): array
{
    return [
        'ref' => 'refs/heads/main',
        'after' => PUSH_SHA,
        'deleted' => false,
        'repository' => ['id' => 777],
        'installation' => ['id' => 42],
        'head_commit' => ['message' => 'fix: tombol login'],
        ...$overrides,
    ];
}

function sendPush(object $test, array $payload, string $delivery = PUSH_DELIVERY, ?string $secret = 'webhook-secret'): mixed
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', route('api.v1.webhooks.github'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GITHUB_DELIVERY' => $delivery,
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
    ], $body);
}

test('a push to the project branch creates a deployment with the pushed commit', function (): void {
    $project = pushProject();

    sendPush($this, pushPayload())->assertOk()->assertJsonPath('duplicate', false);

    $deployment = Deployment::query()->where('project_id', $project->id)->sole();

    expect($deployment->commit_sha)->toBe(PUSH_SHA)
        ->and($deployment->commit_message)->toBe('fix: tombol login')
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Webhook)
        ->and($deployment->requested_by)->toBe($project->user_id)
        ->and($deployment->status)->toBe(DeploymentStatus::Queued);

    expect(AgentCommand::query()->where('deployment_id', $deployment->id)->sole()->payload['commit_sha'])->toBe(PUSH_SHA);
});

test('a redelivery of the same delivery creates nothing new', function (): void {
    $project = pushProject();

    sendPush($this, pushPayload())->assertOk()->assertJsonPath('duplicate', false);
    sendPush($this, pushPayload())->assertOk()->assertJsonPath('duplicate', true);

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(GithubWebhookDelivery::query()->where('delivery_id', PUSH_DELIVERY)->count())->toBe(1);
});

test('a bad signature is rejected before anything happens', function (): void {
    $project = pushProject();

    sendPush($this, pushPayload(), secret: 'wrong-secret')->assertUnauthorized();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(GithubWebhookDelivery::query()->count())->toBe(0);
});

test('a push that does not match creates no deployment', function (array $projectAttributes, array $payload): void {
    $project = pushProject($projectAttributes);

    sendPush($this, pushPayload($payload))->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0);
})->with([
    'not opted in' => [['auto_deploy' => false], []],
    'other branch' => [[], ['ref' => 'refs/heads/develop']],
    'a tag' => [[], ['ref' => 'refs/tags/main']],
    'other repository' => [[], ['repository' => ['id' => 888]]],
    'other installation' => [[], ['installation' => ['id' => 43]]],
    'branch deleted' => [[], ['deleted' => true, 'after' => '0000000000000000000000000000000000000000']],
    'malformed commit' => [[], ['after' => 'not-a-sha']],
]);

test('a removed installation never deploys', function (): void {
    $project = pushProject(status: GithubInstallationStatus::Removed);

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0);
});

test('a soft deleted project never deploys', function (): void {
    $project = pushProject();
    $project->delete();

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0);
});

test('a suspended project is skipped, audited, and the owner is told', function (): void {
    $project = pushProject(['status' => ProjectStatus::Suspended]);

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'project.auto_deploy_skipped')->where('subject_id', $project->id)->sole()->metadata['reason'])->toBe('project_suspended')
        ->and($project->user->notifications()->sole()->data['category'])->toBe('project_issue');
});

test('the active deployment limit holds, and the webhook still succeeds', function (): void {
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);
    $project = pushProject();
    Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Building, 'sequence' => 1]);

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'project.auto_deploy_skipped')->sole()->metadata['reason'])->toBe('active_deployment_limit');

    // The delivery stays recorded, so a redelivery does not retry the skip.
    sendPush($this, pushPayload())->assertOk()->assertJsonPath('duplicate', true);
});

test('auto_deploy is opt in and owner controlled', function (): void {
    $project = pushProject(['auto_deploy' => false]);
    $user = $project->user;

    expect($project->fresh()->auto_deploy)->toBeFalse();

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => true])
        ->assertOk()
        ->assertJsonPath('data.auto_deploy', true);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => 'sometimes'])
        ->assertUnprocessable();

    app('auth')->forgetGuards();

    $this->actingAs(User::factory()->create(), 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => false])
        ->assertForbidden();
});

test('a push from an owner removed on GitHub is skipped and audited', function (): void {
    $project = pushProject();
    Http::swap(new Factory);
    Http::fake(['api.github.com/repositories/777' => Http::response(['message' => 'Not Found'], 404)]);

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'project.auto_deploy_skipped')->sole()->metadata['reason'])->toBe('repository_access_revoked')
        ->and($project->user->notifications()->sole()->data['category'])->toBe('project_issue');
});

test('a push during a GitHub outage still creates the deployment, and the lease decides later', function (): void {
    $project = pushProject();
    Http::swap(new Factory);
    Http::fake(['api.github.com/repositories/777' => Http::response(['message' => 'boom'], 502)]);

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('a pushed commit message is stored like a manual one', function (string $message, ?string $expected): void {
    $project = pushProject();

    sendPush($this, pushPayload(['head_commit' => ['message' => $message]]))->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->sole()->commit_message)->toBe($expected);
})->with([
    'over the column' => [str_repeat('é', 600), str_repeat('é', 500)],
    'only the first line' => ["fix: login\n\nbody that would otherwise be kept", 'fix: login'],
    'markup left as written' => ['<b>fix</b>', '<b>fix</b>'],
    'empty' => ['', null],
]);

test('a push without a head commit has no message', function (): void {
    $project = pushProject();

    sendPush($this, pushPayload(['head_commit' => null]))->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->sole()->commit_message)->toBeNull();
});

test('a push to a team project deploys as its creator while they can deploy there', function (): void {
    $project = pushProject();
    $team = teamWorkspace(User::factory()->create(), [[$project->user, WorkspaceRole::Member]]);
    $project->update(['workspace_id' => $team->id]);

    sendPush($this, pushPayload())->assertOk();

    expect(Deployment::query()->where('project_id', $project->id)->sole()->requested_by)->toBe($project->user_id);
});

test('a push to a team project whose creator left deploys as the longest-standing owner', function (): void {
    $project = pushProject();
    $firstOwner = User::factory()->create();
    $team = teamWorkspace($firstOwner);
    $this->travel(1)->minute();
    addWorkspaceMember($team, User::factory()->create(), WorkspaceRole::Owner);
    $project->update(['workspace_id' => $team->id]);

    sendPush($this, pushPayload())->assertOk();

    // The organisation installation carries the access, so nobody's GitHub token was asked.
    expect(Deployment::query()->where('project_id', $project->id)->sole()->requested_by)->toBe($firstOwner->id);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.github.com/repositories/'));
});

test('a skipped push on a team project tells its owners and members, not its viewers', function (): void {
    $project = pushProject(['status' => ProjectStatus::Suspended]);
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $team = teamWorkspace($owner, [[$project->user, WorkspaceRole::Member], [$viewer, WorkspaceRole::Viewer]]);
    $project->update(['workspace_id' => $team->id]);

    sendPush($this, pushPayload())->assertOk();

    expect($owner->notifications()->count())->toBe(1)
        ->and($project->user->notifications()->count())->toBe(1)
        ->and($viewer->notifications()->count())->toBe(0);
});
