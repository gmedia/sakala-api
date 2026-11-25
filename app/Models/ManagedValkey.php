<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ValkeyLockSource;
use App\Enums\ValkeyMode;
use App\Enums\ValkeyStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ManagedValkeyFactory;
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
 * A managed Valkey store (ADR-023): owned by a workspace, one container on
 * one node with the `managed-valkey` capability, on its own internal network,
 * attached to projects of the same workspace that run on that node. Named
 * like ManagedDatabase and ManagedBucket; the table is `valkeys`.
 *
 * @property string $id Also the agent's `valkey_id`; the node names the store's network, container, and volume after it.
 * @property string|null $workspace_id Null only after an account deletion removed the personal workspace while the delete was on its way.
 * @property string $name Shown to people; unique per workspace among live stores.
 * @property ValkeyMode $mode Chosen at creation, fixed in v1.
 * @property ValkeyStatus $status
 * @property string|null $region The effective region of `node_id` when the store was created.
 * @property string $node_id The Valkey node that runs it; attached projects run there too.
 * @property string|null $password Password of the `default` user, encrypted at rest; null once deleted.
 * @property int $maxmemory_mb `maxmemory` the node applies or will apply.
 * @property int $reserved_maxmemory_mb What counts toward the workspace and node allocation: the larger of `maxmemory_mb` and an open resize.
 * @property string|null $network `sakala-valkey-<id>`, from CreateValkey's result; null until the node created it.
 * @property string|null $host IPv4 address of the store on its network, from CreateValkey's result.
 * @property int|null $port Always 6379 once set.
 * @property ValkeyLockSource|null $lock_source Why the store is locked, set when a lock is requested.
 * @property int|null $used_memory_bytes `used_memory` from the node's last reading.
 * @property int|null $evicted_keys Keys evicted since the store started (cache mode).
 * @property int|null $errorstat_oom Writes refused because the store was full, since it started (persistent mode).
 * @property int|null $connected_clients
 * @property string|null $aof_last_write_status `ok` or `err`; persistent stores only.
 * @property string|null $container_status Docker state of the store container, or `missing`.
 * @property int|null $restart_count
 * @property CarbonImmutable|null $usage_checked_at
 * @property CarbonImmutable|null $memory_warned_at When the 80 % notice was sent; cleared below 80 %.
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'workspace_id',
    'name',
    'mode',
    'status',
    'region',
    'node_id',
    'password',
    'maxmemory_mb',
    'reserved_maxmemory_mb',
    'network',
    'host',
    'port',
    'lock_source',
    'used_memory_bytes',
    'evicted_keys',
    'errorstat_oom',
    'connected_clients',
    'aof_last_write_status',
    'container_status',
    'restart_count',
    'usage_checked_at',
    'memory_warned_at',
    'created_by',
])]
#[Hidden(['password'])]
class ManagedValkey extends Model
{
    /** @use HasFactory<ManagedValkeyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'valkeys';

    /** Longest display name. */
    public const MAX_NAME_LENGTH = 100;

    /** Sizes (`maxmemory`, MB) a store may have in v1 (ADR-023). The agent accepts 16 to 512. */
    public const SIZES_MB = [32, 64, 128];

    /** Size of a store created without one, in MB. */
    public const DEFAULT_SIZE_MB = 64;

    /** Smallest `maxmemory_mb` the agent accepts, for reading heartbeats. */
    public const AGENT_MIN_MEMORY_MB = 16;

    /** Largest `maxmemory_mb` the agent accepts. */
    public const AGENT_MAX_MEMORY_MB = 512;

    /**
     * MB the node budget counts per store on top of its `maxmemory`
     * (`SAKALA_VALKEY_TOTAL_MEMORY_MB` bounds Σ(maxmemory_mb + this)).
     */
    public const NODE_OVERHEAD_MB = 64;

    /** Length of a generated password: letters and digits only, safe in `REDIS_URL`. The agent accepts 32 to 128. */
    public const PASSWORD_LENGTH = 32;

    /** Prefix of the store's network on the node, followed by its ID. */
    public const NETWORK_PREFIX = 'sakala-valkey-';

    /** The port the store listens on, always. */
    public const PORT = 6379;

    /** Network names the agent accepts: the prefix and a lowercase hyphenated UUID. */
    public const NETWORK_PATTERN = '/^sakala-valkey-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

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

    /** @return HasMany<ValkeyAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ValkeyAttachment::class, 'valkey_id');
    }

    /**
     * Projects the store is attached to. Deleting a project removes its
     * attachment, so these are live projects.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'valkey_attachments', 'valkey_id', 'project_id')
            ->withTimestamps();
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class, 'valkey_id');
    }

    /**
     * The newest command of the store, for `last_failure`.
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function latestCommand(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'valkey_id')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
    }

    /**
     * The newest password rotation of the store, for `password_rotation`.
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function latestPasswordRotation(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'valkey_id')
            ->where('type', AgentCommandType::RotateValkeyPassword)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * The open ResizeValkey of the store, for `pending_maxmemory_mb`.
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function openResize(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'valkey_id')
            ->where('type', AgentCommandType::ResizeValkey)
            ->whereIn('status', AgentCommandStatus::openCases())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    /** The open command of the type for this store, if any. */
    public function openCommand(AgentCommandType $type): ?AgentCommand
    {
        return $this->commands()
            ->where('type', $type)
            ->whereIn('status', AgentCommandStatus::openCases())
            ->latest('created_at')
            ->first();
    }

    /** `sakala-valkey-<id>`: the store's network, container, and volume on its node. */
    public static function networkNameFor(string $id): string
    {
        return self::NETWORK_PREFIX.strtolower($id);
    }

    /** The store ID inside a network name the agent would accept, or null. */
    public static function idFromNetwork(mixed $network): ?string
    {
        if (! is_string($network) || preg_match(self::NETWORK_PATTERN, $network) !== 1) {
            return null;
        }

        return substr($network, strlen(self::NETWORK_PREFIX));
    }

    /**
     * Stores that count toward `max_valkeys` and the workspace's Valkey memory.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', ValkeyStatus::countedCases());
    }

    /**
     * Live stores that keep their workspace from being deleted: every one not
     * already on its way out.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHoldingWorkspace(Builder $query): Builder
    {
        return $query->where('status', '!=', ValkeyStatus::Deleting);
    }

    /** Stores of the workspace that count toward its store count. */
    public static function countIn(string $workspaceId): int
    {
        return self::query()->where('workspace_id', $workspaceId)->counted()->count();
    }

    /**
     * MB of `maxmemory` the workspace's counted stores reserve: what
     * `max_valkey_memory_mb` compares. Allocated, not used.
     */
    public static function reservedMbIn(string $workspaceId, ?string $except = null): int
    {
        return (int) self::query()
            ->where('workspace_id', $workspaceId)
            ->counted()
            ->when($except !== null, static fn (Builder $query) => $query->whereKeyNot($except))
            ->sum('reserved_maxmemory_mb');
    }

    /**
     * MB of the node budget the live, non-failed stores of the node take:
     * Σ(reserved `maxmemory` + NODE_OVERHEAD_MB), the agent's own formula. A
     * failed creation's leftovers are the agent's to count.
     */
    public static function budgetMbOnNode(string $nodeId, ?string $except = null): int
    {
        $stores = self::query()
            ->where('node_id', $nodeId)
            ->where('status', '!=', ValkeyStatus::Failed)
            ->when($except !== null, static fn (Builder $query) => $query->whereKeyNot($except));

        return (int) (clone $stores)->sum('reserved_maxmemory_mb') + $stores->count() * self::NODE_OVERHEAD_MB;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => ValkeyMode::class,
            'status' => ValkeyStatus::class,
            'lock_source' => ValkeyLockSource::class,
            'password' => 'encrypted',
            'maxmemory_mb' => 'integer',
            'reserved_maxmemory_mb' => 'integer',
            'port' => 'integer',
            'used_memory_bytes' => 'integer',
            'evicted_keys' => 'integer',
            'errorstat_oom' => 'integer',
            'connected_clients' => 'integer',
            'restart_count' => 'integer',
            'usage_checked_at' => 'immutable_datetime',
            'memory_warned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
