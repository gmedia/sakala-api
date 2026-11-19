<?php

declare(strict_types=1);

use App\Actions\Database\DeployAfterDatabaseReadyAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\WorkspaceRole;
use App\Jobs\Database\DeployAfterDatabaseReadyJob;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\DatabaseAttachment;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Database\DatabaseAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const FOR_PROJECT_BRANCH_HEAD = '4e5f60718293a4b5c6d7e8f9012345678a1b2c3d';

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => FOR_PROJECT_BRANCH_HEAD, 'commit' => ['message' => 'feat: buku tamu']],
        ], 200),
    ]);
});

/**
 * One node that runs projects and hosts databases, whose instance answers, so
 * `databases.available` is true; in `$region` (none by default).
 */
function forProjectNode(?string $region = null, array $metadata = []): AgentNode
{
    return databaseNode($region, overrides: [
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', AgentCommandType::MANAGED_POSTGRES_CAPABILITY],
        'metadata' => [
            'runtime' => ['region' => $region],
            'workloads' => [],
            'database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT], 'available' => true],
            ...$metadata,
        ],
    ]);
}

function forProjectProject(Workspace $workspace, array $attributes = []): Project
{
    return Project::factory()->inWorkspace($workspace)->create(['name' => 'Buku Tamu', 'branch' => 'main', ...$attributes]);
}

function createDatabaseFor(Workspace $workspace, array $body): TestResponse
{
    return test()->postJson("/api/v1/app/workspaces/{$workspace->id}/databases", $body);
}

function projectDatabases(Project $project): array
{
    return test()->getJson("/api/v1/app/projects/{$project->id}")->assertOk()->json('data.databases');
}

/** The node reports the CreateDatabase command of the database as done. */
function reportDatabaseCreated(ManagedDatabase $database, AgentNode $node): void
{
    $command = databaseCommand($database, AgentCommandType::CreateDatabase);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();
}

function reportDatabaseFailed(ManagedDatabase $database, AgentNode $node): void
{
    $command = databaseCommand($database, AgentCommandType::CreateDatabase);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'database_admin_unavailable', 'error_message' => 'instance down'])
        ->assertNoContent();
}

/** A member asks for a database for the project, deployed once ready. */
function databaseForProjectWaiting(array $team, Project $project): ManagedDatabase
{
    test()->actingAs($team['member'], 'web');

    $id = createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'deploy_when_ready' => true])
        ->assertCreated()
        ->json('data.id');

    return ManagedDatabase::query()->findOrFail($id);
}

function forProjectAttachment(Project $project): DatabaseAttachment
{
    return DatabaseAttachment::query()->where('project_id', $project->id)->sole();
}

/** @return list<string> */
function forProjectAuditActions(ManagedDatabase $database): array
{
    return AuditEvent::query()->where('subject_id', $database->id)->orderBy('id')->pluck('action')->all();
}

// ─── Creating a database for a project ───────────────────────────────────────

test('a member creates a database for a project: provisioning, attached, audited, and waiting to deploy', function (): void {
    $node = forProjectNode('jkt');
    $team = databaseTeam();
    $project = forProjectProject($team['workspace'], ['region' => 'jkt']);
    $fingerprintBefore = $project->database_fingerprint;

    $this->actingAs($team['member'], 'web');

    $response = createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'deploy_when_ready' => true])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Buku Tamu')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.region', 'jkt')
        ->assertJsonPath('data.projects.0.id', $project->id);

    $database = ManagedDatabase::query()->findOrFail($response->json('data.id'));
    $attachment = forProjectAttachment($project);
    $command = databaseCommand($database, AgentCommandType::CreateDatabase);

    expect($attachment->database_id)->toBe($database->id)
        ->and($attachment->created_by)->toBe($team['member']->id)
        ->and($attachment->deploy_requested_by)->toBe($team['member']->id)
        ->and($attachment->deploy_requested_at)->not->toBeNull()
        ->and($attachment->deploy_resolved_at)->toBeNull()
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($project->fresh()->database_fingerprint)->not->toBe($fingerprintBefore)
        ->and(forProjectAuditActions($database))->toBe([
            DatabaseAudit::CREATED,
            DatabaseAudit::ATTACHED,
            DatabaseAudit::PROJECT_DEPLOY_REQUESTED,
        ])
        ->and(AuditEvent::query()->where('action', DatabaseAudit::CREATED)->sole()->metadata['project_id'])->toBe($project->id)
        ->and(Deployment::query()->count())->toBe(0);

    $databases = projectDatabases($project);
    expect($databases)->toHaveCount(1)
        ->and($databases[0]['status'])->toBe('provisioning')
        ->and($databases[0]['deploy_when_ready'])->toBe([
            'status' => 'waiting',
            'requested_at' => $attachment->deploy_requested_at->toAtomString(),
            'deployment_id' => null,
            'skipped_reason' => null,
        ]);
});

test('without deploy_when_ready the database is attached with no deploy request', function (): void {
    forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);

    $this->actingAs($team['owner'], 'web');
    $id = createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'name' => 'Data utama'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Data utama')
        ->json('data.id');

    expect(forProjectAttachment($project)->deploy_requested_at)->toBeNull()
        ->and(forProjectAuditActions(ManagedDatabase::query()->findOrFail($id)))->not->toContain(DatabaseAudit::PROJECT_DEPLOY_REQUESTED)
        ->and(projectDatabases($project)[0]['deploy_when_ready'])->toBeNull();
});

test('the default name is the project name, made unique with a number and cut to fit', function (): void {
    forProjectNode();
    $team = databaseTeam(['max_databases' => 10]);
    $node = AgentNode::query()->sole();
    databaseIn($team['workspace'], $node, ['name' => 'Buku Tamu']);
    databaseIn($team['workspace'], $node, ['name' => 'Buku Tamu 2']);
    // A dropped database frees its name.
    databaseIn($team['workspace'], $node, ['name' => 'Buku Tamu 3'])->delete();
    $project = forProjectProject($team['workspace']);
    $long = forProjectProject($team['workspace'], ['name' => str_repeat('a', 120)]);
    $longToo = forProjectProject($team['workspace'], ['name' => str_repeat('a', 120)]);

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id])->assertCreated()->assertJsonPath('data.name', 'Buku Tamu 3');
    createDatabaseFor($team['workspace'], ['project_id' => $long->id])->assertCreated()->assertJsonPath('data.name', str_repeat('a', ManagedDatabase::MAX_NAME_LENGTH));
    createDatabaseFor($team['workspace'], ['project_id' => $longToo->id])->assertCreated()->assertJsonPath('data.name', str_repeat('a', ManagedDatabase::MAX_NAME_LENGTH - 2).' 2');
});

test('the region defaults to the project region, and an explicit other region is refused', function (): void {
    forProjectNode('jkt');
    forProjectNode('sby');
    $team = databaseTeam();
    $project = forProjectProject($team['workspace'], ['region' => 'sby']);

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'region' => 'jkt'])
        ->assertConflict()
        ->assertJsonPath('code', 'DATABASE_CROSS_REGION_UNSUPPORTED')
        ->assertJsonPath('database_region', 'jkt')
        ->assertJsonPath('project_region', 'sby');

    expect(ManagedDatabase::query()->count())->toBe(0);

    createDatabaseFor($team['workspace'], ['project_id' => $project->id])
        ->assertCreated()
        ->assertJsonPath('data.region', 'sby');
});

test('a project without a region takes the workspace default region', function (): void {
    forProjectNode('jkt');
    forProjectNode('sby');
    $team = databaseTeam(['default_region' => 'jkt']);
    $project = forProjectProject($team['workspace']);

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id])->assertCreated()->assertJsonPath('data.region', 'jkt');
});

test('a project that already uses a database is refused and nothing is created', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $existing = databaseIn($team['workspace'], $node, ['name' => 'Lama']);
    attachDatabase($existing, $project);

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_DATABASE_ALREADY_ATTACHED')
        ->assertJsonPath('database', ['id' => $existing->id, 'name' => 'Lama']);

    expect(ManagedDatabase::query()->count())->toBe(1)
        ->and(AgentCommand::query()->count())->toBe(0);
});

test('a project of another workspace, or no project at all, is a 404 and creates nothing', function (): void {
    forProjectNode();
    $team = databaseTeam();
    $other = databaseTeam();
    $foreign = forProjectProject($other['workspace']);
    $deleted = forProjectProject($team['workspace']);
    $deleted->delete();

    $this->actingAs($team['owner'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $foreign->id])->assertNotFound();
    createDatabaseFor($team['workspace'], ['project_id' => $deleted->id])->assertNotFound();
    createDatabaseFor($team['workspace'], ['project_id' => '0195f6a2-7c1d-7000-8000-000000000000'])->assertNotFound();
    createDatabaseFor($team['workspace'], ['project_id' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('project_id');

    expect(ManagedDatabase::query()->count())->toBe(0)
        ->and(DatabaseAttachment::query()->count())->toBe(0);
});

test('who may create a database for a project', function (string $role, int $status): void {
    forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);

    $this->actingAs($team[$role], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'deploy_when_ready' => true])->assertStatus($status);

    expect(DatabaseAttachment::query()->count())->toBe($status === 201 ? 1 : 0);
})->with([
    'owner' => ['owner', 201],
    'member' => ['member', 201],
    'viewer' => ['viewer', 403],
    'outsider' => ['outsider', 403],
    'admin outside the workspace' => ['admin', 201],
]);

test('the database count and storage quotas hold for a project too, and nothing is attached', function (array $profile, array $sizes, string $code): void {
    $node = forProjectNode();
    $team = databaseTeam($profile);
    $project = forProjectProject($team['workspace']);

    foreach ($sizes as $i => $bytes) {
        databaseIn($team['workspace'], $node, ['name' => "Ada {$i}", 'size_bytes' => $bytes]);
    }

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id])
        ->assertUnprocessable()
        ->assertJsonPath('code', $code);

    expect(DatabaseAttachment::query()->count())->toBe(0)
        ->and(ManagedDatabase::query()->count())->toBe(count($sizes));
})->with([
    'count' => [['max_databases' => 1], [0], 'DATABASE_LIMIT_EXCEEDED'],
    'storage' => [['max_databases' => 3, 'max_database_storage_mb' => 1], [2 * 1024 * 1024], 'DATABASE_STORAGE_LIMIT_EXCEEDED'],
]);

test('while no database server is available a database for a project is refused, but one on its own still waits', function (): void {
    // Online and allowed work, but its instance does not answer.
    forProjectNode(metadata: ['database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT], 'available' => false]]);
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'deploy_when_ready' => true])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Server database sedang tidak tersedia, jadi database untuk proyek ini belum bisa dibuat. Deploy proyek tanpa database dulu, atau coba lagi nanti.',
            'code' => 'DATABASE_UNAVAILABLE',
        ]);

    expect(ManagedDatabase::query()->count())->toBe(0);

    createDatabaseFor($team['workspace'], ['name' => 'Tanpa proyek'])->assertCreated();
});

test('deploy_when_ready needs a project and a boolean, and name is required without a project', function (array $body, string $field): void {
    forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $body = array_map(static fn (mixed $value): mixed => $value === 'PROJECT' ? $project->id : $value, $body);

    $this->actingAs($team['member'], 'web');

    createDatabaseFor($team['workspace'], $body)->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(ManagedDatabase::query()->count())->toBe(0);
})->with([
    'deploy_when_ready without project' => [['name' => 'Data', 'deploy_when_ready' => true], 'deploy_when_ready'],
    'deploy_when_ready false without project' => [['name' => 'Data', 'deploy_when_ready' => false], 'deploy_when_ready'],
    'deploy_when_ready with a null project' => [['name' => 'Data', 'project_id' => null, 'deploy_when_ready' => true], 'deploy_when_ready'],
    'deploy_when_ready not a boolean' => [['project_id' => 'PROJECT', 'deploy_when_ready' => 'yes'], 'deploy_when_ready'],
    'no name and no project' => [[], 'name'],
]);

test('when a later step fails, the database, command, attachment, fingerprint, and audit all roll back', function (): void {
    forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $fingerprintBefore = $project->database_fingerprint;

    // The very last write of the transaction.
    AuditEvent::creating(static function (AuditEvent $event): void {
        if ($event->action === DatabaseAudit::PROJECT_DEPLOY_REQUESTED) {
            throw new RuntimeException('audit store down');
        }
    });

    $this->withoutExceptionHandling();
    $this->actingAs($team['member'], 'web');

    expect(fn () => createDatabaseFor($team['workspace'], ['project_id' => $project->id, 'deploy_when_ready' => true]))
        ->toThrow(RuntimeException::class, 'audit store down');

    expect(ManagedDatabase::withTrashed()->count())->toBe(0)
        ->and(AgentCommand::query()->count())->toBe(0)
        ->and(DatabaseAttachment::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('subject_type', ManagedDatabase::class)->count())->toBe(0)
        ->and($project->fresh()->database_fingerprint)->toBe($fingerprintBefore);
});

test('the attach endpoint still refuses a database that is being created', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $project = forProjectProject($team['workspace']);

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}/attachments/{$project->id}")
        ->assertConflict()
        ->assertJsonPath('code', 'DATABASE_NOT_READY')
        ->assertJsonPath('status', 'provisioning');

    expect(DatabaseAttachment::query()->count())->toBe(0);
});

// ─── The deployment once the database is ready ───────────────────────────────

test('once the node reports the database created, the project is deployed once as the requester', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseForProjectWaiting($team, $project);

    reportDatabaseCreated($database, $node);

    $deployment = Deployment::query()->sole();
    $attachment = forProjectAttachment($project);

    expect($deployment->project_id)->toBe($project->id)
        ->and($deployment->requested_by)->toBe($team['member']->id)
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Manual)
        ->and($deployment->commit_sha)->toBe(FOR_PROJECT_BRANCH_HEAD)
        ->and($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->idempotency_key)->toBe("database-ready:{$attachment->id}")
        ->and($attachment->deploy_deployment_id)->toBe($deployment->id)
        ->and($attachment->deploy_resolved_at)->not->toBeNull()
        ->and(databaseStatus($database))->toBe(DatabaseStatus::Ready);

    $started = AuditEvent::query()->where('action', DatabaseAudit::PROJECT_DEPLOY_STARTED)->sole();
    expect($started->metadata['deployment_id'])->toBe($deployment->id)
        ->and($started->actor_id)->toBe((string) $team['member']->id);

    // The deployment received the connection variables.
    $payload = AgentCommand::query()->where('deployment_id', $deployment->id)->sole()->payload;
    expect($payload['environment'])->toHaveKeys(['DATABASE_URL', 'DB_PASSWORD']);

    expect(projectDatabases($project)[0]['deploy_when_ready'])->toMatchArray([
        'status' => 'started',
        'deployment_id' => $deployment->id,
        'skipped_reason' => null,
    ]);

    // Running again, as a duplicate job would, does nothing.
    app(DeployAfterDatabaseReadyAction::class)->handle($database->id);
    expect(Deployment::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', DatabaseAudit::PROJECT_DEPLOY_STARTED)->count())->toBe(1);
});

test('a request that was resolved meanwhile is not resolved twice, and its deployment is reused', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseForProjectWaiting($team, $project);

    Queue::fake();
    reportDatabaseCreated($database, $node);
    Queue::assertPushed(DeployAfterDatabaseReadyJob::class, 1);

    // Two workers pick the same database up.
    $action = app(DeployAfterDatabaseReadyAction::class);
    $action->handle($database->id);
    forProjectAttachment($project)->update(['deploy_resolved_at' => null, 'deploy_deployment_id' => null]);
    $action->handle($database->id);

    expect(Deployment::query()->count())->toBe(1)
        ->and(forProjectAttachment($project)->deploy_deployment_id)->toBe(Deployment::query()->sole()->id);
});

test('a failed creation skips the deployment with a reason and tells the requester', function (string $how): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseForProjectWaiting($team, $project);

    if ($how === 'fail') {
        reportDatabaseFailed($database, $node);
    } else {
        $command = databaseCommand($database, AgentCommandType::CreateDatabase);
        $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now(), 'lease_expires_at' => now()->subMinute()]);
        $this->artisan('agent:expire-commands')->assertSuccessful();
    }

    $attachment = forProjectAttachment($project);
    $skipped = AuditEvent::query()->where('action', DatabaseAudit::PROJECT_DEPLOY_SKIPPED)->sole();
    $notification = $team['member']->notifications()->sole();

    expect(Deployment::query()->count())->toBe(0)
        ->and($attachment->deploy_skipped_reason)->toBe('database_provisioning_failed')
        ->and($attachment->deploy_resolved_at)->not->toBeNull()
        ->and($skipped->metadata['reason'])->toBe('database_provisioning_failed')
        ->and($skipped->actor_type)->toBe($how === 'fail' ? AgentNode::class : 'system')
        ->and($notification->data['category'])->toBe('project_issue')
        ->and($notification->data['project_id'])->toBe($project->id)
        ->and($notification->data['message'])->toContain($database->name)
        // Only the requester, while they can act on the project.
        ->and($team['owner']->notifications()->count())->toBe(0);

    expect(projectDatabases($project)[0]['deploy_when_ready'])->toMatchArray([
        'status' => 'skipped',
        'deployment_id' => null,
        'skipped_reason' => 'database_provisioning_failed',
    ]);
})->with(['fail', 'expire']);

test('a requester who lost access to the workspace is skipped', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseForProjectWaiting($team, $project);
    WorkspaceMember::query()->where('workspace_id', $team['workspace']->id)->where('user_id', $team['member']->id)->update(['role' => WorkspaceRole::Viewer->value]);

    reportDatabaseCreated($database, $node);

    expect(Deployment::query()->count())->toBe(0)
        ->and(forProjectAttachment($project)->deploy_skipped_reason)->toBe('requester_cannot_deploy')
        ->and(AuditEvent::query()->where('action', DatabaseAudit::PROJECT_DEPLOY_SKIPPED)->sole()->metadata['reason'])->toBe('requester_cannot_deploy');
});

test('a requester whose account is gone is skipped', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseForProjectWaiting($team, $project);
    // The column is nulled when the account is deleted.
    forProjectAttachment($project)->update(['deploy_requested_by' => null]);

    reportDatabaseCreated($database, $node);

    expect(Deployment::query()->count())->toBe(0)
        ->and(forProjectAttachment($project)->deploy_skipped_reason)->toBe('requester_cannot_deploy');
});

test('a workspace limit skips the deployment with its reason', function (): void {
    $node = forProjectNode();
    $team = databaseTeam(['max_active_deployments' => 1]);
    $project = forProjectProject($team['workspace']);
    $busy = forProjectProject($team['workspace'], ['name' => 'Sibuk']);
    Deployment::factory()->for($busy)->create(['status' => DeploymentStatus::Building, 'sequence' => 1]);
    $database = databaseForProjectWaiting($team, $project);

    reportDatabaseCreated($database, $node);

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(forProjectAttachment($project)->deploy_skipped_reason)->toBe('active_deployment_limit');
});

test('detaching before the database is ready means nothing is deployed', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseForProjectWaiting($team, $project);

    $this->actingAs($team['member'], 'web')
        ->deleteJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}/attachments/{$project->id}")
        ->assertNoContent();

    Queue::fake();
    reportDatabaseCreated($database, $node);

    Queue::assertNotPushed(DeployAfterDatabaseReadyJob::class);
    app(DeployAfterDatabaseReadyAction::class)->handle($database->id);

    expect(Deployment::query()->count())->toBe(0)
        ->and(databaseStatus($database))->toBe(DatabaseStatus::Ready);
});

test('a database created for a project without deploy_when_ready deploys nothing when ready', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);

    $this->actingAs($team['member'], 'web');
    $id = createDatabaseFor($team['workspace'], ['project_id' => $project->id])->assertCreated()->json('data.id');

    Queue::fake();
    reportDatabaseCreated(ManagedDatabase::query()->findOrFail($id), $node);

    Queue::assertNotPushed(DeployAfterDatabaseReadyJob::class);
    expect(Deployment::query()->count())->toBe(0);
});

// ─── Deploy guard ────────────────────────────────────────────────────────────

test('a project whose attached database is provisioning or failed cannot be deployed', function (DatabaseStatus $status): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    attachDatabase(databaseIn($team['workspace'], $node, ['status' => $status]), $project);

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'DATABASE_NOT_READY')
        ->assertJsonPath('status', $status->value);

    expect(Deployment::query()->count())->toBe(0);
})->with([DatabaseStatus::Provisioning, DatabaseStatus::Failed]);

test('a ready or locked attached database does not hold deployments back', function (DatabaseStatus $status): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    attachDatabase(databaseIn($team['workspace'], $node, ['status' => $status]), $project);

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();
})->with([DatabaseStatus::Ready, DatabaseStatus::Locked]);

test('a database attached through the attach endpoint shows no deploy request', function (): void {
    $node = forProjectNode();
    $team = databaseTeam();
    $project = forProjectProject($team['workspace']);
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}/attachments/{$project->id}")
        ->assertCreated();

    expect(projectDatabases($project)[0]['deploy_when_ready'])->toBeNull();
});
