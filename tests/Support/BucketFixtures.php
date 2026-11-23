<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\BucketStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\BucketAttachment;
use App\Models\LimitProfile;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bucket\BucketCommandService;
use Illuminate\Support\Str;

const STORAGE_NODE_ENDPOINT = 'https://s3.sakala.test';

const STORAGE_NODE_REGION = 'us-east-1';

const STORAGE_NODE_CAPACITY_MB = 5120;

/**
 * An online node with the `managed-s3` capability that reported its object
 * storage, in `$region` (none by default).
 */
function storageNode(?string $region = null, array $overrides = [], bool $endpoint = true, int $capacityMb = STORAGE_NODE_CAPACITY_MB, bool $available = true): AgentNode
{
    $agentId = 'agent-'.Str::uuid7();

    return AgentNode::factory()->create([
        'agent_id' => $agentId,
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'desired_state' => AgentNodeDesiredState::Active,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => [AgentCommandType::MANAGED_S3_CAPABILITY],
        'metadata' => [
            'runtime' => ['region' => $region],
            ...($endpoint ? ['object_storage' => [
                'endpoint' => STORAGE_NODE_ENDPOINT,
                'region' => STORAGE_NODE_REGION,
                'capacity_mb' => $capacityMb,
                'available' => $available,
            ]] : []),
        ],
        'token_hash' => hash_hmac('sha256', storageNodeToken($agentId), (string) config('app.key')),
        ...$overrides,
    ]);
}

function storageNodeToken(string $agentId): string
{
    return "token-{$agentId}";
}

/** @return array<string, string> */
function storageNodeHeaders(AgentNode $node): array
{
    return ['Authorization' => 'Bearer '.storageNodeToken($node->agent_id), 'X-Agent-Id' => $node->agent_id];
}

/**
 * A team with a user in every role, plus an outsider and an admin, under a
 * profile with room for buckets.
 *
 * @return array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}
 */
function bucketTeam(array $profile = []): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $viewer = User::factory()->create();

    $workspace = teamWorkspace($owner, [[$member, WorkspaceRole::Member], [$viewer, WorkspaceRole::Viewer]], [
        'limit_profile_id' => LimitProfile::factory()->create([
            'max_active_containers' => 5,
            'max_active_deployments' => 5,
            'max_buckets' => 3,
            'max_bucket_storage_mb' => 1000,
            ...$profile,
        ])->id,
    ]);

    return [
        'workspace' => $workspace,
        'owner' => $owner,
        'member' => $member,
        'viewer' => $viewer,
        'outsider' => User::factory()->create(),
        'admin' => User::factory()->create(['role' => UserRole::Admin]),
    ];
}

function bucketsUrl(string $workspaceId, string $suffix = ''): string
{
    return "/api/v1/app/workspaces/{$workspaceId}/buckets{$suffix}";
}

function bucketIn(Workspace $workspace, AgentNode $node, array $attributes = []): ManagedBucket
{
    return ManagedBucket::factory()->inWorkspace($workspace)->onNode($node)->create($attributes);
}

function attachBucket(ManagedBucket $bucket, Project $project): BucketAttachment
{
    $attachment = BucketAttachment::query()->create(['bucket_id' => $bucket->id, 'project_id' => $project->id]);
    $project->refreshBucketFingerprint();
    $project->save();

    return $attachment;
}

/** The newest command of the type for the bucket, as the API created it. */
function bucketCommand(ManagedBucket $bucket, AgentCommandType $type): AgentCommand
{
    return AgentCommand::query()->where('bucket_id', $bucket->id)->where('type', $type)->latest('created_at')->orderByDesc('id')->firstOrFail();
}

/** A command for the bucket in the given status, created the way the API does. */
function issuedBucketCommand(ManagedBucket $bucket, AgentCommandType $type, AgentCommandStatus $status = AgentCommandStatus::Running, ?string $secret = null, int $quotaMb = 20): AgentCommand
{
    $service = app(BucketCommandService::class);

    $command = match ($type) {
        AgentCommandType::CreateBucket => $service->create($bucket, $secret ?? (string) $bucket->secret_access_key),
        AgentCommandType::DeleteBucket => $service->delete($bucket),
        AgentCommandType::RotateBucketKey => $service->rotateKey($bucket, $secret ?? 'RotatedSecret0123456789abcdefghijklmnopqr'),
        AgentCommandType::SetBucketQuota => $service->setQuota($bucket, $quotaMb),
        AgentCommandType::LockBucket => $service->lock($bucket),
        AgentCommandType::UnlockBucket => $service->unlock($bucket),
        default => throw new InvalidArgumentException('Not a bucket command.'),
    };

    $command->update(['status' => $status, 'claimed_at' => $status === AgentCommandStatus::Pending ? null : now()]);

    return $command->refresh();
}

function bucketStatus(ManagedBucket $bucket): BucketStatus
{
    return ManagedBucket::withTrashed()->findOrFail($bucket->id)->status;
}

/**
 * A full heartbeat of a storage node: the base payload with the object
 * storage fields of the protocol fixture, optionally replaced.
 *
 * @param  array<string, mixed>|null  $objectStorage
 * @param  list<array{name: string, size_bytes: int}>|null  $buckets
 */
function storageHeartbeat(?array $objectStorage = null, ?array $buckets = null): array
{
    $fixture = agentHeartbeatFixture('object-storage-host');
    $payload = heartbeatPayload(['capabilities' => $fixture['capabilities']]);
    $payload['metadata']['object_storage'] = $objectStorage ?? $fixture['metadata']['object_storage'];

    if ($buckets !== null) {
        $payload['metadata']['buckets'] = $buckets;
        $payload['metadata']['detail_counts']['buckets'] = count($buckets);
    }

    return $payload;
}
