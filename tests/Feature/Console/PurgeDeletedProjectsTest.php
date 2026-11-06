<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\EnvironmentVariable;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\RetiredProjectSlug;
use App\Models\User;
use App\Notifications\UserAlertNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const PURGE_SECRET = 'purge-test-secret-value';

function purgeNode(array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => ['docker-runtime', 'project-inspection', 'dockerfile-build', 'railpack-build'],
        'token_hash' => hash_hmac('sha256', 'purge-token', (string) config('app.key')),
        ...$overrides,
    ]);
}

function purgeHeaders(AgentNode $node): array
{
    return ['Authorization' => 'Bearer purge-token', 'X-Agent-Id' => $node->agent_id];
}

/**
 * A project that served one deployment, with a row in every table a purge has
 * to reach, including the copy of the encrypted env value on the DeployProject.
 *
 * @return array{User, Project, Deployment, string}
 */
function projectWithData(AgentNode $node, array $attributes = []): array
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'runtime_status' => RuntimeStatus::Running,
        'status' => ProjectStatus::Active,
        ...$attributes,
    ]);
    $env = EnvironmentVariable::factory()->for($project)->create([
        'key' => 'API_SECRET',
        'encrypted_value' => PURGE_SECRET,
        'is_secret' => true,
    ]);
    $ciphertext = (string) $env->getRawOriginal('encrypted_value');
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Succeeded,
        'payload' => ['environment' => ['API_SECRET' => $ciphertext]],
    ]);
    DeploymentLog::factory()->for($deployment)->create();
    DeploymentEvent::factory()->for($deployment)->create();

    return [$user, $project->fresh(), $deployment, $ciphertext];
}

function stopCommandOf(Project $project): AgentCommand
{
    return AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->sole();
}

/** Delete through the API, then let the node finish the stop it was given. */
function deleteAndStop(User $user, Project $project, AgentNode $node): void
{
    test()->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    $stop = stopCommandOf($project);

    test()->withHeaders(purgeHeaders($node))->postJson("/api/agent/v1/commands/{$stop->id}/claim", [])->assertOk();
    test()->withHeaders(purgeHeaders($node))->postJson("/api/agent/v1/commands/{$stop->id}/complete", ['result' => null])->assertNoContent();
}

/** Every value in every table, so a copy nobody thought of still fails the test. */
function databaseContains(string $needle): bool
{
    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        foreach (DB::table($table)->get() as $row) {
            if (str_contains((string) json_encode($row), $needle)) {
                return true;
            }
        }
    }

    return false;
}

test('a deleted project stays for the retention window, then everything about it is gone', function (): void {
    config(['sakala.project.deleted_retention_days' => 30]);
    $node = purgeNode();
    [$user, $project, $deployment, $ciphertext] = projectWithData($node);
    $user->notify(new UserAlertNotification(NotificationCategory::DeploymentSuccess, 'Deployment berhasil', "{$project->name} sudah live.", $project->id, $deployment->id));
    $feedback = Feedback::factory()->for($user)->create(['project_id' => $project->id, 'deployment_id' => $deployment->id]);
    deleteAndStop($user, $project, $node);

    $this->travel(29)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->count())->toBe(1);

    $this->travel(2)->days();
    expect(databaseContains($ciphertext))->toBeTrue();

    $this->artisan('projects:purge-deleted')->assertSuccessful();

    expect(Project::withTrashed()->find($project->id))->toBeNull()
        ->and(Deployment::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(DeploymentLog::query()->where('deployment_id', $deployment->id)->count())->toBe(0)
        ->and(DeploymentEvent::query()->where('deployment_id', $deployment->id)->count())->toBe(0)
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and($user->notifications()->count())->toBe(0)
        ->and(databaseContains($ciphertext))->toBeFalse()
        ->and(databaseContains(PURGE_SECRET))->toBeFalse()
        ->and(databaseContains($project->name))->toBeFalse()
        ->and(databaseContains($project->repository_url))->toBeFalse();

    // What the docs promise stays: the user's own feedback, and the audit trail.
    expect($feedback->fresh()->project_id)->toBeNull()
        ->and($feedback->fresh()->deployment_id)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'project.purged')->where('subject_id', $project->id)->exists())->toBeTrue();
});

test('the retention window follows the config', function (): void {
    config(['sakala.project.deleted_retention_days' => 7]);
    $project = Project::factory()->create(['deleted_at' => now()->subDays(8)]);
    $younger = Project::factory()->create(['deleted_at' => now()->subDays(6)]);

    $this->artisan('projects:purge-deleted')->assertSuccessful();

    expect(Project::withTrashed()->find($project->id))->toBeNull()
        ->and(Project::withTrashed()->find($younger->id))->not->toBeNull();
});

test('a live project and its data are never touched, however old', function (): void {
    [, $project] = projectWithData(purgeNode(), ['created_at' => now()->subYears(2)]);

    $this->travel(2)->years();
    $this->artisan('projects:purge-deleted')->assertSuccessful();

    expect(Project::query()->find($project->id))->not->toBeNull()
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('a purged slug stays reserved, so a new project gets another address', function (): void {
    Http::fake(['api.github.com/repos/*/commits*' => Http::response([['sha' => str_repeat('a', 40), 'commit' => ['message' => 'init']]], 200)]);
    $user = User::factory()->create();
    $this->actingAs($user, 'web');
    $body = ['name' => 'Same Name', 'repository_url' => 'https://github.com/example/same', 'branch' => 'main'];

    $first = $this->postJson('/api/v1/app/projects', $body)->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/app/projects/{$first}")->assertNoContent();

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();

    expect(Project::withTrashed()->find($first))->toBeNull()
        ->and(RetiredProjectSlug::query()->find('same-name'))->not->toBeNull();

    $this->getJson(route('api.v1.app.projects.domain-preview', ['name' => 'Same Name']))
        ->assertOk()
        ->assertJsonPath('data.slug', 'same-name-1');

    $second = $this->postJson('/api/v1/app/projects', $body)->assertCreated()->json('data.id');

    expect(Project::query()->findOrFail($second)->slug)->toBe('same-name-1');
});

test('with the node away the secrets still go on time, and the stop can still be delivered', function (): void {
    $node = purgeNode(['status' => AgentNodeStatus::Offline]);
    [$user, $project, , $ciphertext] = projectWithData($node);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    $stop = stopCommandOf($project);

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')
        ->expectsTable(['Metric', 'Count'], [['Projects Purged', '0'], ['Projects Waiting For A Node', '1'], ['Projects Failed', '0']])
        ->assertSuccessful();

    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and($stop->fresh()->status)->toBe(AgentCommandStatus::Pending)
        ->and(databaseContains($ciphertext))->toBeFalse()
        ->and(databaseContains(PURGE_SECRET))->toBeFalse();

    $node->update(['status' => AgentNodeStatus::Ready, 'last_seen_at' => now()]);
    $this->withHeaders(purgeHeaders($node))->getJson('/api/agent/v1/commands')->assertJsonPath('data.0.id', $stop->id);
    $this->withHeaders(purgeHeaders($node))->postJson("/api/agent/v1/commands/{$stop->id}/claim", [])->assertOk();

    // Claimed is not finished, so the row still waits.
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect(Project::withTrashed()->find($project->id))->not->toBeNull();

    $this->withHeaders(purgeHeaders($node))->postJson("/api/agent/v1/commands/{$stop->id}/complete", ['result' => null])->assertNoContent();

    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect(Project::withTrashed()->find($project->id))->toBeNull();
});

test('a failed stop is asked again once a day instead of orphaning the container', function (): void {
    $node = purgeNode();
    [$user, $project, $deployment] = projectWithData($node);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    stopCommandOf($project)->update(['status' => AgentCommandStatus::Failed, 'failed_at' => now()]);

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();

    $stops = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject);
    $retry = (clone $stops)->where('status', AgentCommandStatus::Pending)->sole();

    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and($retry->deployment_id)->toBe($deployment->id)
        ->and($retry->agent_node_id)->toBe($node->id);

    // The same day a pending retry is enough, and a failed one is not repeated.
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect((clone $stops)->count())->toBe(2);

    $retry->update(['status' => AgentCommandStatus::Failed, 'failed_at' => now()]);
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect((clone $stops)->count())->toBe(2);

    $this->travel(1)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect((clone $stops)->count())->toBe(3);
});

test('an attempt still in flight holds the purge until its node reports back', function (): void {
    $node = purgeNode();
    [, $project] = projectWithData($node, ['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);
    $project->update(['active_deployment_id' => null]);
    $attempt = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Building, 'sequence' => 2, 'agent_node_id' => $node->id]);
    $project->delete();

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect(Project::withTrashed()->find($project->id))->not->toBeNull();

    $attempt->update(['status' => DeploymentStatus::Failed]);
    $this->artisan('projects:purge-deleted')->assertSuccessful();
    expect(Project::withTrashed()->find($project->id))->toBeNull();
});

test('a revoked node cannot receive anything, so it does not hold the purge', function (): void {
    $node = purgeNode();
    [$user, $project] = projectWithData($node);

    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    $node->update(['auth_status' => AgentAuthStatus::Revoked]);

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted')->assertSuccessful();

    expect(Project::withTrashed()->find($project->id))->toBeNull();
});

test('a dry run reports the outcome and changes nothing', function (): void {
    $node = purgeNode();
    [$user, $project, , $ciphertext] = projectWithData($node);
    $this->actingAs($user, 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();
    $idle = Project::factory()->create(['deleted_at' => now()]);

    $this->travel(31)->days();
    $this->artisan('projects:purge-deleted', ['--dry-run' => true])
        ->expectsTable(['Metric', 'Count'], [['Projects Eligible', '1'], ['Projects Waiting For A Node', '1'], ['Projects Failed', '0']])
        ->assertSuccessful();

    expect(Project::withTrashed()->find($project->id))->not->toBeNull()
        ->and(Project::withTrashed()->find($idle->id))->not->toBeNull()
        ->and(databaseContains($ciphertext))->toBeTrue()
        ->and(AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->count())->toBe(1);
});

test('a retention below one day stops the run instead of erasing the window', function (array $arguments, ?int $configured): void {
    config(['sakala.project.deleted_retention_days' => $configured ?? 30]);
    $project = Project::factory()->create(['deleted_at' => now()->subHour()]);

    $this->artisan('projects:purge-deleted', $arguments)->assertFailed();

    expect(Project::withTrashed()->find($project->id))->not->toBeNull();
})->with([
    'option' => [['--days' => 0], null],
    'config' => [[], 0],
]);

test('one project that cannot be deleted does not keep the others past their window', function (): void {
    $stuck = Project::factory()->create(['deleted_at' => now()->subDays(40)]);
    $other = Project::factory()->create(['deleted_at' => now()->subDays(40)]);

    Project::forceDeleting(function (Project $project) use ($stuck): void {
        if ($project->id === $stuck->id) {
            throw new RuntimeException('boom');
        }
    });

    try {
        $this->artisan('projects:purge-deleted')->assertFailed();
    } finally {
        Project::flushEventListeners();
        Project::clearBootedModels();
    }

    expect(Project::withTrashed()->find($stuck->id))->not->toBeNull()
        ->and(Project::withTrashed()->find($other->id))->toBeNull()
        ->and(RetiredProjectSlug::query()->find($stuck->slug))->toBeNull();
});

test('the purge is scheduled daily without overlap on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'projects:purge-deleted'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
