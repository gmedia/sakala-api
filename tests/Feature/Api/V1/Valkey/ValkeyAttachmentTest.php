<?php

declare(strict_types=1);

use App\Enums\ValkeyStatus;
use App\Models\AuditEvent;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\ValkeyAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

test('attaching a ready store asks for a redeploy, detaching before one gives the old fingerprint back, and both are audited', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node);
    $live = deployLive($project, $team['owner']);

    expect($project->fresh()->requiresRedeploy())->toBeFalse();

    $this->actingAs($team['member'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))
        ->assertCreated()
        ->assertJsonPath('data.valkey_id', $valkey->id)
        ->assertJsonPath('data.project.id', $project->id)
        ->assertJsonPath('data.redeploy_required', true);

    // Again: a no-op.
    $this->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))->assertOk();

    $this->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertJsonPath('data.projects.0.id', $project->id);

    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))->assertNoContent();

    expect(ValkeyAttachment::query()->count())->toBe(0)
        ->and($project->fresh()->requiresRedeploy())->toBeFalse()
        ->and($live->agent_node_id)->toBe($node->id)
        ->and(AuditEvent::query()->whereIn('action', ['valkey.attached', 'valkey.detached'])->pluck('action')->all())->toBe(['valkey.attached', 'valkey.detached']);

    $this->deleteJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))->assertNotFound();
});

test('a project uses one store at a time', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $first = valkeyIn($team['workspace'], $node, ['name' => 'pertama']);
    $second = valkeyIn($team['workspace'], $node, ['name' => 'kedua']);
    attachValkey($first, $project);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$second->id}/attachments/{$project->id}"))
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_VALKEY_ALREADY_ATTACHED')
        ->assertJsonPath('valkey.id', $first->id);
});

test('a store attaches to several projects of its workspace on its node', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $other = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create();
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web');
    $this->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))->assertCreated();
    $this->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$other->id}"))->assertCreated();

    expect($valkey->projects()->count())->toBe(2);
});

test('a project served by another node cannot use the store: VALKEY_NODE_MISMATCH', function (): void {
    $storeNode = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $project->deployments()->create([
        'sequence' => 1,
        'status' => 'succeeded',
        'agent_node_id' => valkeyNode()->id,
        'branch' => 'main',
        'trigger' => 'manual',
    ]);
    $valkey = valkeyIn($team['workspace'], $storeNode);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_NODE_MISMATCH')
        ->assertJsonPath('valkey.id', $valkey->id);
});

test('a locked or unready store does not attach', function (ValkeyStatus $status, string $code): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node, ['status' => $status]);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))
        ->assertConflict()
        ->assertJsonPath('code', $code);
})->with([
    'locked' => [ValkeyStatus::Locked, 'VALKEY_LOCKED'],
    'provisioning' => [ValkeyStatus::Provisioning, 'VALKEY_NOT_READY'],
    'failed' => [ValkeyStatus::Failed, 'VALKEY_NOT_READY'],
]);

test('a project or store of another workspace is not found, and viewers cannot attach', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $foreign = Project::factory()->create();
    $valkey = valkeyIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$foreign->id}"))
        ->assertNotFound();

    $this->actingAs($team['viewer'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/attachments/{$project->id}"))
        ->assertForbidden();
});

test('creating a store for a project attaches it in the same step, named after the project', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject(profile: ['max_valkeys' => 1, 'max_valkey_memory_mb' => 64]);

    $id = $this->actingAs($team['member'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id), ['mode' => 'persistent', 'project_id' => $project->id])
        ->assertCreated()
        ->assertJsonPath('data.name', $project->name)
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.projects.0.id', $project->id)
        ->json('data.id');

    expect(ManagedValkey::query()->findOrFail($id)->node_id)->toBe($node->id)
        ->and($project->fresh()->valkey_fingerprint)->not->toBeNull();

    // Another project of another workspace answers 404.
    $this->postJson(valkeysUrl($team['workspace']->id), ['mode' => 'cache', 'project_id' => Project::factory()->create()->id])
        ->assertNotFound();
});

test('deleting a project detaches its store, which outlives it', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node);
    attachValkey($valkey, $project);

    $this->actingAs($team['owner'], 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertSuccessful();

    expect(ValkeyAttachment::query()->count())->toBe(0)
        ->and($valkey->fresh()->status)->toBe(ValkeyStatus::Ready)
        ->and(AuditEvent::query()->where('action', 'valkey.detached')->sole()->metadata['reason'])->toBe('project_deleted');
});

test('a team with a store cannot be deleted', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    valkeyIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson("/api/v1/app/workspaces/{$team['workspace']->id}")
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Workspace ini masih punya Valkey. Hapus semuanya dulu, lalu hapus workspace-nya.',
            'code' => 'WORKSPACE_NOT_EMPTY',
            'projects' => 0,
            'databases' => 0,
            'buckets' => 0,
            'valkeys' => 1,
        ]);
});
