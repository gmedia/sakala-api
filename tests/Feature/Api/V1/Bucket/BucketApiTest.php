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
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

test('creating a bucket picks a storage node, random names and key, and asks the node to create it', function (): void {
    $node = storageNode('jkt');
    $team = bucketTeam();

    $response = $this->actingAs($team['member'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['name' => '  Unggahan  ', 'quota_mb' => 50])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Unggahan')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.region', 'jkt')
        ->assertJsonPath('data.quota_mb', 50)
        ->assertJsonPath('data.pending_quota_mb', null)
        ->assertJsonPath('data.size_bytes', null)
        ->assertJsonPath('data.connection', null)
        ->assertJsonPath('data.key_rotation', null)
        ->assertJsonPath('data.projects', []);

    expect($response->json('data'))->not->toHaveKeys(['secret_access_key', 's3_bucket', 'access_key_id', 'node_id', 'reserved_quota_mb']);

    $bucket = ManagedBucket::query()->findOrFail($response->json('data.id'));
    $command = bucketCommand($bucket, AgentCommandType::CreateBucket);

    expect($bucket->node_id)->toBe($node->id)
        ->and($bucket->s3_bucket)->toMatch('/^b-[a-z0-9]{20}$/')
        ->and($bucket->access_key_id)->toMatch('/^SK[A-Z0-9]{18}$/')
        ->and((string) $bucket->secret_access_key)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($bucket->getRawOriginal('secret_access_key'))->not->toBe($bucket->secret_access_key)
        ->and($bucket->quota_mb)->toBe(50)
        ->and($bucket->reserved_quota_mb)->toBe(50)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->expires_at)->toBeNull()
        ->and($command->project_id)->toBeNull()
        ->and($command->payload['bucket'])->toBe($bucket->s3_bucket)
        ->and($command->payload['access_key_id'])->toBe($bucket->access_key_id)
        ->and($command->payload['quota_mb'])->toBe(50)
        // Encrypted at rest, like a database password.
        ->and($command->payload[AgentCommandPayloadMaterializer::SECRET_KEY])->not->toBe($bucket->secret_access_key)
        ->and(Crypt::decryptString($command->payload[AgentCommandPayloadMaterializer::SECRET_KEY]))->toBe($bucket->secret_access_key);

    $audit = AuditEvent::query()->where('action', 'bucket.created')->sole();
    expect($audit->subject_id)->toBe($bucket->id)
        ->and($audit->actor_id)->toBe((string) $team['member']->id)
        ->and($audit->metadata['s3_bucket'])->toBe($bucket->s3_bucket)
        ->and($audit->metadata['quota_mb'])->toBe(50)
        ->and(json_encode($audit->metadata))->not->toContain((string) $bucket->secret_access_key);
});

test('the authorization matrix for reading and changing buckets', function (string $role, int $list, int $create, int $rename, int $delete): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['name' => 'Lama']);
    $user = $team[$role];

    $this->actingAs($user, 'web')->getJson(bucketsUrl($team['workspace']->id))->assertStatus($list);
    $this->actingAs($user, 'web')->getJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))->assertStatus($list);
    $this->actingAs($user, 'web')->postJson(bucketsUrl($team['workspace']->id), ['name' => "Baru {$role}", 'quota_mb' => 1])->assertStatus($create);
    $this->actingAs($user, 'web')->patchJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"), ['name' => "Ganti {$role}"])->assertStatus($rename);
    $this->actingAs($user, 'web')->deleteJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))->assertStatus($delete);
})->with([
    'owner' => ['owner', 200, 201, 200, 202],
    'member' => ['member', 200, 201, 200, 202],
    'viewer' => ['viewer', 200, 403, 403, 403],
    'admin' => ['admin', 200, 201, 200, 202],
    'outsider' => ['outsider', 403, 403, 403, 403],
]);

test('a bucket of another workspace is not found through this one, and its own workspace refuses outsiders', function (): void {
    $node = storageNode();
    $mine = bucketTeam();
    $theirs = bucketTeam();
    $foreign = bucketIn($theirs['workspace'], $node);

    foreach (['', '/credentials'] as $suffix) {
        $this->actingAs($mine['owner'], 'web')
            ->getJson(bucketsUrl($mine['workspace']->id, "/{$foreign->id}{$suffix}"))
            ->assertNotFound();
    }

    $this->actingAs($mine['owner'], 'web')->patchJson(bucketsUrl($mine['workspace']->id, "/{$foreign->id}"), ['name' => 'Curi'])->assertNotFound();
    $this->actingAs($mine['owner'], 'web')->deleteJson(bucketsUrl($mine['workspace']->id, "/{$foreign->id}"))->assertNotFound();
    $this->actingAs($mine['owner'], 'web')->postJson(bucketsUrl($mine['workspace']->id, "/{$foreign->id}/rotate-key"))->assertNotFound();

    $this->actingAs($mine['owner'], 'web')
        ->getJson(bucketsUrl($theirs['workspace']->id, "/{$foreign->id}/credentials"))
        ->assertForbidden();

    expect($foreign->fresh()?->name)->not->toBe('Curi')
        ->and($foreign->fresh()?->status)->toBe(BucketStatus::Ready)
        ->and(AuditEvent::query()->where('action', 'bucket.credentials_viewed')->exists())->toBeFalse();
});

test('the list is newest first and leaves deleted buckets out', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $old = bucketIn($team['workspace'], $node, ['name' => 'Lama', 'created_at' => now()->subDay()]);
    $new = bucketIn($team['workspace'], $node, ['name' => 'Baru']);
    $gone = bucketIn($team['workspace'], $node, ['name' => 'Hilang', 'status' => BucketStatus::Deleted]);
    $gone->delete();

    $this->actingAs($team['viewer'], 'web')
        ->getJson(bucketsUrl($team['workspace']->id))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id);
});

test('names are unique among live buckets of a workspace, and a deleted bucket frees its name', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $taken = bucketIn($team['workspace'], $node, ['name' => 'Media']);

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['name' => 'Media', 'quota_mb' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    $taken->forceFill(['status' => BucketStatus::Deleted, 'deleted_at' => now()])->save();

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['name' => 'Media', 'quota_mb' => 1])
        ->assertCreated();

    // Another workspace may use the same name.
    $other = bucketTeam();
    $this->actingAs($other['owner'], 'web')
        ->postJson(bucketsUrl($other['workspace']->id), ['name' => 'Media', 'quota_mb' => 1])
        ->assertCreated();
});

test('the body is validated', function (array $body, string $field): void {
    storageNode();
    $team = bucketTeam();

    $this->actingAs($team['owner'], 'web')
        ->postJson(bucketsUrl($team['workspace']->id), $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'no name without a project' => [[], 'name'],
    'name too long' => [['name' => str_repeat('a', 101)], 'name'],
    'quota zero' => [['name' => 'A', 'quota_mb' => 0], 'quota_mb'],
    'quota above 1 TiB' => [['name' => 'A', 'quota_mb' => 1048577], 'quota_mb'],
    'quota not an integer' => [['name' => 'A', 'quota_mb' => 'banyak'], 'quota_mb'],
    'bad region' => [['name' => 'A', 'region' => 'Jakarta Raya'], 'region'],
    'deploy_when_ready is not accepted in v1' => [['name' => 'A', 'deploy_when_ready' => false], 'deploy_when_ready'],
]);

test('creating a bucket needs the current legal terms accepted', function (): void {
    storageNode();
    $team = bucketTeam();
    $user = User::factory()->withoutLegalAcceptance()->create();
    $team['workspace']->members()->create(['user_id' => $user->id, 'role' => WorkspaceRole::Owner]);

    $this->actingAs($user, 'web')
        ->postJson(bucketsUrl($team['workspace']->id), ['name' => 'Berkas', 'quota_mb' => 1])
        ->assertForbidden()
        ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');

    // Reading stays open.
    $this->actingAs($user, 'web')->getJson(bucketsUrl($team['workspace']->id))->assertOk();

    expect(ManagedBucket::query()->count())->toBe(0);
});

test('renaming never touches the node', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['name' => 'Lama']);

    $this->actingAs($team['member'], 'web')
        ->patchJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"), ['name' => 'Baru'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Baru');

    expect(AgentCommand::query()->where('bucket_id', $bucket->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'bucket.renamed')->sole()->metadata)->toMatchArray(['from' => 'Lama', 'to' => 'Baru']);

    $this->actingAs($team['member'], 'web')
        ->patchJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"), [])
        ->assertUnprocessable();
});

test('deleting a ready bucket asks the node to delete it, and asking again changes nothing', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')
        ->deleteJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'deleting');

    $command = bucketCommand($bucket, AgentCommandType::DeleteBucket);
    expect($command->payload)->toBe(['bucket' => $bucket->s3_bucket, 'access_key_id' => $bucket->access_key_id]);

    $this->actingAs($team['member'], 'web')
        ->deleteJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))
        ->assertAccepted();

    expect(AgentCommand::query()->where('bucket_id', $bucket->id)->where('type', AgentCommandType::DeleteBucket)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'bucket.delete_requested')->count())->toBe(1);
});

test('a failed delete can be asked again, and a failed bucket gets a DeleteBucket too', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $deleting = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Deleting]);
    issuedBucketCommand($deleting, AgentCommandType::DeleteBucket, AgentCommandStatus::Failed);
    $failed = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Failed]);

    $this->actingAs($team['owner'], 'web')->deleteJson(bucketsUrl($team['workspace']->id, "/{$deleting->id}"))->assertAccepted();
    $this->actingAs($team['owner'], 'web')->deleteJson(bucketsUrl($team['workspace']->id, "/{$failed->id}"))->assertAccepted();

    expect(AgentCommand::query()->where('bucket_id', $deleting->id)->where('type', AgentCommandType::DeleteBucket)->count())->toBe(2)
        ->and(bucketCommand($failed, AgentCommandType::DeleteBucket)->status)->toBe(AgentCommandStatus::Pending)
        ->and(bucketStatus($failed))->toBe(BucketStatus::Deleting);
});

test('a bucket still provisioning and not claimed is removed at once with 204; a claimed one is busy', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $pending = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    $create = issuedBucketCommand($pending, AgentCommandType::CreateBucket, AgentCommandStatus::Pending);
    $claimed = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Provisioning]);
    issuedBucketCommand($claimed, AgentCommandType::CreateBucket, AgentCommandStatus::Claimed);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson(bucketsUrl($team['workspace']->id, "/{$pending->id}"))
        ->assertNoContent();

    $this->actingAs($team['owner'], 'web')
        ->deleteJson(bucketsUrl($team['workspace']->id, "/{$claimed->id}"))
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_BUSY');

    $removed = ManagedBucket::withTrashed()->findOrFail($pending->id);

    expect($removed->trashed())->toBeTrue()
        ->and($removed->secret_access_key)->toBeNull()
        ->and($create->fresh()?->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($create->fresh()?->payload)->not->toHaveKey(AgentCommandPayloadMaterializer::SECRET_KEY)
        ->and(bucketStatus($claimed))->toBe(BucketStatus::Provisioning);
});

test('an attached bucket cannot be deleted', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko']);
    attachBucket($bucket, $project);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_IN_USE')
        ->assertJsonPath('projects.0.id', $project->id)
        ->assertJsonPath('projects.0.name', 'Toko');

    expect(bucketStatus($bucket))->toBe(BucketStatus::Ready);
});
