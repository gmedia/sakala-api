<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ManagedBucketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A managed S3 bucket (ADR-021): owned by a workspace, hosted on one node
 * with the `managed-s3` capability, attached to projects of the same
 * workspace. Named ManagedBucket because `bucket` already means a time bucket
 * in the metric tables; the table is `buckets`.
 *
 * @property string $id
 * @property string|null $workspace_id Null only after an account deletion removed the personal workspace while the delete was on its way.
 * @property string $name Shown to people; unique per workspace among live buckets.
 * @property string|null $region The effective region of `node_id` when the bucket was created (Sakala placement, not the S3 signing region).
 * @property BucketStatus $status
 * @property string $node_id The storage node that hosts it.
 * @property string $s3_bucket Random bucket name on the node (`b-` and lowercase letters and digits); never reused.
 * @property string $access_key_id Random access key ID, also the RustFS user name; not secret, stable across rotations.
 * @property string|null $secret_access_key Encrypted at rest, null once deleted.
 * @property int $quota_mb Hard quota the node applies or will apply.
 * @property int $reserved_quota_mb What counts toward the workspace and node allocation: the larger of `quota_mb` and an open resize.
 * @property int|null $size_bytes Usage from the node's last heartbeat that listed it.
 * @property CarbonImmutable|null $usage_checked_at
 * @property CarbonImmutable|null $storage_warned_at When the 80 % notice was sent; cleared below 80 %.
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'workspace_id',
    'name',
    'region',
    'status',
    'node_id',
    's3_bucket',
    'access_key_id',
    'secret_access_key',
    'quota_mb',
    'reserved_quota_mb',
    'size_bytes',
    'usage_checked_at',
    'storage_warned_at',
    'created_by',
])]
#[Hidden(['secret_access_key'])]
class ManagedBucket extends Model
{
    /** @use HasFactory<ManagedBucketFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'buckets';

    /** Longest display name. */
    public const MAX_NAME_LENGTH = 100;

    /** Prefix of the random bucket name on the node. */
    public const S3_NAME_PREFIX = 'b-';

    /** Random characters after the prefix: lowercase letters and digits. The agent accepts 16 to 40. */
    public const S3_NAME_RANDOM_LENGTH = 20;

    /** Shape of a bucket name the node reports in a heartbeat; anything else cannot be one of ours. */
    public const S3_NAME_PATTERN = '/^b-[a-z0-9]{16,40}$/';

    /** Prefix of the random access key ID. */
    public const ACCESS_KEY_PREFIX = 'SK';

    /** Random characters after the prefix: uppercase letters and digits. The agent accepts 16 to 38. */
    public const ACCESS_KEY_RANDOM_LENGTH = 18;

    /** Length of a generated secret access key: letters and digits only. The agent accepts 32 to 128. */
    public const SECRET_LENGTH = 40;

    /** Smallest bucket quota, in MB. */
    public const MIN_QUOTA_MB = 1;

    /** Largest bucket quota, in MB (1 TiB). */
    public const MAX_QUOTA_MB = 1048576;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<AgentNode, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(AgentNode::class, 'node_id');
    }

    /** @return HasMany<BucketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(BucketAttachment::class, 'bucket_id');
    }

    /**
     * Projects the bucket is attached to. Deleting a project removes its
     * attachment, so these are live projects.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'bucket_attachments', 'bucket_id', 'project_id')
            ->withTimestamps();
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class, 'bucket_id');
    }

    /**
     * The newest command of the bucket, for `last_failure`. Ordered like
     * ManagedDatabase::latestCommand().
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function latestCommand(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'bucket_id')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
    }

    /**
     * The newest key rotation of the bucket, for `key_rotation`.
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function latestKeyRotation(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'bucket_id')
            ->where('type', AgentCommandType::RotateBucketKey)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * The open SetBucketQuota of the bucket, for `pending_quota_mb`.
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function openQuotaChange(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'bucket_id')
            ->where('type', AgentCommandType::SetBucketQuota)
            ->whereIn('status', AgentCommandStatus::openCases())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    /** The open command of the type for this bucket, if any. */
    public function openCommand(AgentCommandType $type): ?AgentCommand
    {
        return $this->commands()
            ->where('type', $type)
            ->whereIn('status', AgentCommandStatus::openCases())
            ->latest('created_at')
            ->first();
    }

    /**
     * Buckets that count toward `max_buckets` and the workspace's reserved storage.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', BucketStatus::countedCases());
    }

    /**
     * Live buckets that keep their workspace from being deleted: every one
     * not already on its way out. A failed creation counts, so its row is
     * deleted on purpose rather than left without an owner.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHoldingWorkspace(Builder $query): Builder
    {
        return $query->where('status', '!=', BucketStatus::Deleting);
    }

    /** Buckets of the workspace that count toward its bucket count. */
    public static function countIn(string $workspaceId): int
    {
        return self::query()->where('workspace_id', $workspaceId)->counted()->count();
    }

    /**
     * MB the workspace's counted buckets reserve: what `max_bucket_storage_mb`
     * compares. Allocated, not used.
     */
    public static function reservedMbIn(string $workspaceId, ?string $except = null): int
    {
        return (int) self::query()
            ->where('workspace_id', $workspaceId)
            ->counted()
            ->when($except !== null, static fn (Builder $query) => $query->whereKeyNot($except))
            ->sum('reserved_quota_mb');
    }

    /** Bytes the workspace's live buckets reported, a bucket never measured counting 0. */
    public static function usageBytesIn(string $workspaceId): int
    {
        return (int) self::query()->where('workspace_id', $workspaceId)->sum('size_bytes');
    }

    /**
     * MB the live buckets of the node reserve, except failed ones (contract
     * section 13): what node placement subtracts from `capacity_mb`. A failed
     * creation's leftovers on the node are the agent's capacity check to
     * count; deleting one sends DeleteBucket, which counts again until done.
     */
    public static function reservedMbOnNode(string $nodeId, ?string $except = null): int
    {
        return (int) self::query()
            ->where('node_id', $nodeId)
            ->where('status', '!=', BucketStatus::Failed)
            ->when($except !== null, static fn (Builder $query) => $query->whereKeyNot($except))
            ->sum('reserved_quota_mb');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => BucketStatus::class,
            'secret_access_key' => 'encrypted',
            'quota_mb' => 'integer',
            'reserved_quota_mb' => 'integer',
            'size_bytes' => 'integer',
            'usage_checked_at' => 'immutable_datetime',
            'storage_warned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
