<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deleteOwnAccountWithBuckets(object $test, User $user): mixed
{
    return $test->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->actingAs($user, 'web')
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email, 'current_password' => 'password']);
}

test('a team with a bucket cannot be deleted until the bucket is on its way out', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $url = "/api/v1/app/workspaces/{$team['workspace']->id}";

    $this->actingAs($team['owner'], 'web')
        ->deleteJson($url)
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Workspace ini masih punya bucket. Hapus semuanya dulu, lalu hapus workspace-nya.',
            'code' => 'WORKSPACE_NOT_EMPTY',
            'projects' => 0,
            'databases' => 0,
            'buckets' => 1,
        ]);

    $this->deleteJson("{$url}/buckets/{$bucket->id}")->assertAccepted();
    $this->deleteJson($url)->assertNoContent();

    $remaining = ManagedBucket::query()->findOrFail($bucket->id);

    expect($remaining->workspace_id)->toBeNull()
        ->and($remaining->status)->toBe(BucketStatus::Deleting)
        ->and(Workspace::query()->whereKey($team['workspace']->id)->exists())->toBeFalse();
});

test('a team with projects, databases, and buckets names all three', function (): void {
    $team = bucketTeam();
    bucketIn($team['workspace'], storageNode());
    databaseIn($team['workspace'], databaseNode());
    Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->deleteJson("/api/v1/app/workspaces/{$team['workspace']->id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Workspace ini masih punya proyek, database dan bucket. Hapus semuanya dulu, lalu hapus workspace-nya.')
        ->assertJsonPath('buckets', 1);
});

test('deleting an account deletes the buckets of its personal workspace, which finish without it', function (): void {
    $node = storageNode();
    $user = User::factory()->create();
    $workspace = $user->personalWorkspace()->firstOrFail();
    $bucket = bucketIn($workspace, $node);
    $project = Project::factory()->for($user)->create();
    attachBucket($bucket, $project);

    deleteOwnAccountWithBuckets($this, $user)->assertNoContent();

    $delete = AgentCommand::query()->where('bucket_id', $bucket->id)->where('type', AgentCommandType::DeleteBucket)->sole();
    $remaining = ManagedBucket::query()->findOrFail($bucket->id);

    expect($remaining->status)->toBe(BucketStatus::Deleting)
        ->and($remaining->workspace_id)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'bucket.delete_requested')->sole()->metadata['reason'])->toBe('account_deleted')
        ->and(User::query()->whereKey($user->id)->exists())->toBeFalse();

    $delete->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$delete->id}/complete", ['result' => null])
        ->assertNoContent();

    expect(ManagedBucket::withTrashed()->findOrFail($bucket->id)->trashed())->toBeTrue();
});

test('account deletion waits for a bucket a node is still creating', function (): void {
    $node = storageNode();
    $user = User::factory()->create();
    $bucket = bucketIn($user->personalWorkspace()->firstOrFail(), $node, ['status' => BucketStatus::Provisioning]);
    issuedBucketCommand($bucket, AgentCommandType::CreateBucket, AgentCommandStatus::Claimed);

    deleteOwnAccountWithBuckets($this, $user)
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_BUSY');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('the sole owner of a team that still has a bucket cannot delete the account', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, [[User::factory()->create(), WorkspaceRole::Member]]);
    bucketIn($team, storageNode());

    deleteOwnAccountWithBuckets($this, $owner)
        ->assertConflict()
        ->assertJsonPath('code', 'LAST_WORKSPACE_OWNER')
        ->assertJsonPath('workspaces.0.id', $team->id);

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});
