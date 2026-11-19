<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\DatabaseAttachment;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attachmentUrl(ManagedDatabase $database, Project $project): string
{
    return "/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/attachments/{$project->id}";
}

/** A project of the workspace whose serving deployment was made from its current configuration. */
function databaseServedProject(Workspace $workspace, ?AgentNode $node = null, array $attributes = []): Project
{
    $project = Project::factory()->inWorkspace($workspace)->create(['runtime_status' => RuntimeStatus::Running, ...$attributes]);
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'agent_node_id' => $node?->id,
        'config_fingerprint' => $project->deploymentConfigFingerprint(),
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    return $project->refresh();
}

test('attaching makes the next deployment need the connection variables, and detaching back clears it', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = databaseServedProject($team['workspace']);

    expect($project->requiresRedeploy())->toBeFalse();

    $this->actingAs($team['member'], 'web')
        ->postJson(attachmentUrl($database, $project))
        ->assertCreated()
        ->assertJsonPath('data.database_id', $database->id)
        ->assertJsonPath('data.project.id', $project->id)
        ->assertJsonPath('data.redeploy_required', true);

    expect($project->fresh()->database_fingerprint)->not->toBeNull()
        ->and($project->fresh()->requiresRedeploy())->toBeTrue();

    // Attaching again changes nothing.
    $this->postJson(attachmentUrl($database, $project))->assertOk();
    expect(DatabaseAttachment::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'database.attached')->count())->toBe(1);

    $this->deleteJson(attachmentUrl($database, $project))->assertNoContent();

    // Back to the configuration the serving deployment was made from.
    expect($project->fresh()->database_fingerprint)->toBeNull()
        ->and($project->fresh()->requiresRedeploy())->toBeFalse()
        ->and(DatabaseAttachment::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'database.detached')->sole()->metadata)->toMatchArray(['project_id' => $project->id]);

    $this->deleteJson(attachmentUrl($database, $project))->assertNotFound();
});

test('the authorization matrix for attaching and detaching', function (string $role, int $attach, int $detach): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team[$role], 'web');
    $this->postJson(attachmentUrl($database, $project))->assertStatus($attach);

    if ($attach !== 201) {
        attachDatabase($database, $project);
    }

    $this->deleteJson(attachmentUrl($database, $project))->assertStatus($detach);
})->with([
    'owner' => ['owner', 201, 204],
    'member' => ['member', 201, 204],
    'viewer' => ['viewer', 403, 403],
    'outsider' => ['outsider', 403, 403],
    'admin' => ['admin', 201, 204],
]);

test('a project of another workspace is not found, whoever asks', function (string $role): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $foreign = Project::factory()->inWorkspace(databaseTeam()['workspace'])->create();

    $this->actingAs($team[$role], 'web')
        ->postJson(attachmentUrl($database, $foreign))
        ->assertNotFound();

    expect(DatabaseAttachment::query()->count())->toBe(0);
})->with(['owner', 'admin']);

test('a database of another workspace is not found under this one', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $foreign = databaseIn(databaseTeam()['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$foreign->id}/attachments/{$project->id}")
        ->assertNotFound();
});

test('a project uses one database at a time', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $first = databaseIn($team['workspace'], $node, ['name' => 'utama']);
    $second = databaseIn($team['workspace'], $node, ['name' => 'cadangan']);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    attachDatabase($first, $project);

    $this->actingAs($team['owner'], 'web')
        ->postJson(attachmentUrl($second, $project))
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Proyek ini sudah memakai database utama. Lepas dulu database itu dari proyek, lalu pasang yang ini.',
            'code' => 'PROJECT_DATABASE_ALREADY_ATTACHED',
            'database' => ['id' => $first->id, 'name' => 'utama'],
        ]);
});

test('one database attaches to several projects of its workspace', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $web = Project::factory()->inWorkspace($team['workspace'])->create();
    $worker = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web');
    $this->postJson(attachmentUrl($database, $web))->assertCreated();
    $this->postJson(attachmentUrl($database, $worker))->assertCreated();

    $this->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.projects');
});

test('a locked or unready database cannot be attached', function (DatabaseStatus $status, string $code): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => $status]);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->postJson(attachmentUrl($database, $project))
        ->assertConflict()
        ->assertJsonPath('code', $code);

    expect(DatabaseAttachment::query()->count())->toBe(0);
})->with([
    'locked' => [DatabaseStatus::Locked, 'DATABASE_LOCKED'],
    'provisioning' => [DatabaseStatus::Provisioning, 'DATABASE_NOT_READY'],
    'failed' => [DatabaseStatus::Failed, 'DATABASE_NOT_READY'],
    'deleting' => [DatabaseStatus::Deleting, 'DATABASE_NOT_READY'],
]);

test('the locked message says why and what to do', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Locked]);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->postJson(attachmentUrl($database, $project))
        ->assertExactJson([
            'message' => 'Database ini dikunci karena penyimpanan database workspace sudah penuh, jadi belum bisa dipasang ke proyek. Hapus database yang tidak dipakai, atau minta admin menaikkan kuota.',
            'code' => 'DATABASE_LOCKED',
        ]);
});

test('attaching across regions is refused, by the serving node or the project region', function (): void {
    $databaseNode = databaseNode('jkt');
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $databaseNode);
    $runtime = AgentNode::factory()->create(['metadata' => ['runtime' => ['region' => 'sby']]]);
    $served = databaseServedProject($team['workspace'], $runtime);
    $placed = Project::factory()->inWorkspace($team['workspace'])->create(['region' => 'sgp']);

    $this->actingAs($team['owner'], 'web');

    $this->postJson(attachmentUrl($database, $served))
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Database ini ada di region jkt, sedangkan proyek ini berjalan di region sby. Database dan proyek harus di region yang sama, jadi buat database baru di region sby untuk proyek ini.',
            'code' => 'DATABASE_CROSS_REGION_UNSUPPORTED',
            'database_region' => 'jkt',
            'project_region' => 'sby',
        ]);

    $this->postJson(attachmentUrl($database, $placed))->assertConflict()->assertJsonPath('project_region', 'sgp');
});

test('a region that is not known on one side does not block attaching', function (?string $databaseRegion, ?string $projectRegion): void {
    $node = databaseNode($databaseRegion);
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['region' => $projectRegion]);

    $this->actingAs($team['owner'], 'web')->postJson(attachmentUrl($database, $project))->assertCreated();
})->with([
    'same region' => ['jkt', 'jkt'],
    'database without region' => [null, 'jkt'],
    'project without region' => ['jkt', null],
]);

test('rotating the password of an attached database asks attached projects for a redeploy once it completes', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = databaseServedProject($team['workspace']);
    attachDatabase($database, $project);
    $project->activeDeployment->update(['config_fingerprint' => $project->fresh()->deploymentConfigFingerprint()]);

    expect($project->fresh()->requiresRedeploy())->toBeFalse();

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}/rotate-password")
        ->assertAccepted();

    // Nothing changes until the node reports.
    expect($project->fresh()->requiresRedeploy())->toBeFalse();

    $rotate = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $rotate->update(['status' => AgentCommandStatus::Running]);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$rotate->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->requiresRedeploy())->toBeTrue();
});
