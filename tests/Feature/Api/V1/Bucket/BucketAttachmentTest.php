<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\AuditEvent;
use App\Models\BucketAttachment;
use App\Models\Deployment;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bucketAttachUrl(ManagedBucket $bucket, Project $project, ?string $workspaceId = null): string
{
    $workspaceId ??= (string) $bucket->workspace_id;

    return "/api/v1/app/workspaces/{$workspaceId}/buckets/{$bucket->id}/attachments/{$project->id}";
}

/** A project that serves a deployment made before anything was attached. */
function bucketServedProject(Workspace $workspace, array $attributes = []): Project
{
    $project = Project::factory()->inWorkspace($workspace)->create(['branch' => 'main', ...$attributes]);
    $deployment = Deployment::factory()->for($project)->create(['config_fingerprint' => $project->deploymentConfigFingerprint()]);
    $project->update(['active_deployment_id' => $deployment->id]);

    return $project->refresh();
}

test('attaching a ready bucket asks for a redeploy; attaching again answers 200; detaching gives the old fingerprint back', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $project = bucketServedProject($team['workspace']);

    $this->actingAs($team['member'], 'web')
        ->postJson(bucketAttachUrl($bucket, $project))
        ->assertCreated()
        ->assertJsonPath('data.bucket_id', $bucket->id)
        ->assertJsonPath('data.project.id', $project->id)
        ->assertJsonPath('data.redeploy_required', true);

    $this->actingAs($team['member'], 'web')->postJson(bucketAttachUrl($bucket, $project))->assertOk();

    expect(BucketAttachment::query()->count())->toBe(1)
        ->and($project->fresh()?->bucket_fingerprint)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'bucket.attached')->count())->toBe(1);

    $this->actingAs($team['member'], 'web')->deleteJson(bucketAttachUrl($bucket, $project))->assertNoContent();

    expect($project->fresh()?->bucket_fingerprint)->toBeNull()
        ->and($project->fresh()?->requiresRedeploy())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'bucket.detached')->sole()->metadata['reason'])->toBe('user');

    $this->actingAs($team['member'], 'web')->deleteJson(bucketAttachUrl($bucket, $project))->assertNotFound();
});

test('a project holds one bucket, and only ready buckets attach', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    $first = bucketIn($team['workspace'], $node, ['name' => 'Pertama']);
    attachBucket($first, $project);

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketAttachUrl(bucketIn($team['workspace'], $node), $project))
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_BUCKET_ALREADY_ATTACHED')
        ->assertJsonPath('bucket.id', $first->id)
        ->assertJsonPath('bucket.name', 'Pertama');

    $other = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketAttachUrl(bucketIn($team['workspace'], $node, ['status' => BucketStatus::Locked]), $other))
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_LOCKED');

    foreach ([BucketStatus::Provisioning, BucketStatus::Failed, BucketStatus::Deleting] as $status) {
        $this->actingAs($team['owner'], 'web')
            ->postJson(bucketAttachUrl(bucketIn($team['workspace'], $node, ['status' => $status]), $other))
            ->assertConflict()
            ->assertJsonPath('code', 'BUCKET_NOT_READY')
            ->assertJsonPath('status', $status->value);
    }
});

test('attaching across workspaces is not found, and viewers and outsiders cannot attach', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $other = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $foreignProject = Project::factory()->inWorkspace($other['workspace'])->create();
    $foreignBucket = bucketIn($other['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')->postJson(bucketAttachUrl($bucket, $foreignProject))->assertNotFound();
    $this->actingAs($team['owner'], 'web')->postJson(bucketAttachUrl($foreignBucket, $project, $team['workspace']->id))->assertNotFound();
    $this->actingAs($team['viewer'], 'web')->postJson(bucketAttachUrl($bucket, $project))->assertForbidden();
    $this->actingAs($team['outsider'], 'web')->postJson(bucketAttachUrl($bucket, $project))->assertForbidden();

    expect(BucketAttachment::query()->count())->toBe(0);
});

test('creating with project_id attaches in the same step, named after the project', function (): void {
    storageNode();
    $team = bucketTeam();
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko Online']);
    $sibling = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko Online']);
    bucketIn($team['workspace'], storageNode(), ['name' => 'Toko Online']);

    $response = $this->actingAs($team['member'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['project_id' => $project->id, 'quota_mb' => 5])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Toko Online 2')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.projects.0.id', $project->id);

    $bucket = ManagedBucket::query()->findOrFail($response->json('data.id'));

    $this->actingAs($team['member'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['project_id' => $sibling->id, 'quota_mb' => 5])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Toko Online 3');

    $this->actingAs($team['member'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['project_id' => $project->id, 'quota_mb' => 5])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_BUCKET_ALREADY_ATTACHED')
        ->assertJsonPath('bucket.id', $bucket->id);

    expect(AuditEvent::query()->where('action', 'bucket.created')->where('subject_id', $bucket->id)->sole()->metadata['project_id'])->toBe($project->id)
        ->and(bucketCommand($bucket, AgentCommandType::CreateBucket))->not->toBeNull();
});

test('creating for a project needs available storage, a project of this workspace, and the right to change it', function (): void {
    $team = bucketTeam();
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    storageNode(available: false);

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['project_id' => $project->id])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_UNAVAILABLE');

    storageNode();
    $foreign = Project::factory()->inWorkspace(bucketTeam()['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['project_id' => $foreign->id])
        ->assertNotFound();

    $this->actingAs($team['viewer'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['project_id' => $project->id])
        ->assertForbidden();

    expect(ManagedBucket::query()->count())->toBe(0);
});

test('deleting a project detaches its bucket, which stays', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    attachBucket($bucket, $project);

    $this->actingAs($team['owner'], 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertSuccessful();

    expect(BucketAttachment::query()->count())->toBe(0)
        ->and(bucketStatus($bucket))->toBe(BucketStatus::Ready)
        ->and(AuditEvent::query()->where('action', 'bucket.detached')->sole()->metadata)->toMatchArray([
            'project_id' => $project->id,
            'reason' => 'project_deleted',
        ]);
});
