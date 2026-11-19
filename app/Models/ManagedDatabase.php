<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ManagedDatabaseFactory;
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
 * A managed PostgreSQL database (ADR-020): owned by a workspace, hosted in the
 * shared instance of one database node, attached to projects of the same
 * workspace. Named ManagedDatabase so it never reads as the framework's
 * database layer; the table is `databases`.
 *
 * @property string $id
 * @property string|null $workspace_id Null only after an account deletion removed the personal workspace while the drop was on its way.
 * @property string $name Shown to people; unique per workspace among live databases.
 * @property DatabaseEngine $engine
 * @property string|null $region The effective region of `node_id` when the database was created.
 * @property DatabaseStatus $status
 * @property string $node_id The database node that hosts it.
 * @property string $pg_database Random database name on the node (`p_` and lowercase letters and digits).
 * @property string $pg_role Random login role on the node, the database's owner; another random value than pg_database.
 * @property string|null $password The role's password; encrypted at rest, null once dropped.
 * @property int|null $size_bytes `pg_database_size()` from the node's last heartbeat that listed it.
 * @property CarbonImmutable|null $size_checked_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'workspace_id',
    'name',
    'engine',
    'region',
    'status',
    'node_id',
    'pg_database',
    'pg_role',
    'password',
    'size_bytes',
    'size_checked_at',
    'created_by',
])]
#[Hidden(['password'])]
class ManagedDatabase extends Model
{
    /** @use HasFactory<ManagedDatabaseFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'databases';

    /** Longest display name. */
    public const MAX_NAME_LENGTH = 100;

    /** Prefix of the random database and role names on the node. */
    public const IDENTIFIER_PREFIX = 'p_';

    /** Random characters after the prefix: lowercase letters and digits. The agent accepts 16 to 40 (`^p_[a-z0-9]{16,40}$`). */
    public const IDENTIFIER_RANDOM_LENGTH = 20;

    /**
     * Shape of a name the node reports in a heartbeat: what PostgreSQL accepts
     * unquoted, so anything else cannot be one of ours.
     */
    public const IDENTIFIER_PATTERN = '/^[a-z_][a-z0-9_]{0,62}$/';

    /** Length of a generated password: letters and digits only. The agent accepts 24 to 128 printable ASCII characters. */
    public const PASSWORD_LENGTH = 40;

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

    /** @return HasMany<DatabaseAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(DatabaseAttachment::class, 'database_id');
    }

    /**
     * Projects the database is attached to. Deleting a project removes its
     * attachment, so these are live projects.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'database_attachments', 'database_id', 'project_id')
            ->withTimestamps();
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class, 'database_id');
    }

    /**
     * The newest command of the database, for `last_failure`. Not
     * latestOfMany(), for the reason Project::latestDeployment() gives:
     * PostgreSQL has no max(uuid). UUIDv7 IDs break a tie within one second.
     *
     * @return HasOne<AgentCommand, $this>
     */
    public function latestCommand(): HasOne
    {
        return $this->hasOne(AgentCommand::class, 'database_id')->orderByDesc('created_at')->orderByDesc('id')->limit(1);
    }

    /** The open command of the type for this database, if any. */
    public function openCommand(AgentCommandType $type): ?AgentCommand
    {
        return $this->commands()
            ->where('type', $type)
            ->whereIn('status', AgentCommandStatus::openCases())
            ->latest('created_at')
            ->first();
    }

    /**
     * Databases that count towards the workspace's database count.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', DatabaseStatus::countedCases());
    }

    /**
     * Live databases that keep their workspace from being deleted: every one
     * not already on its way out. A failed creation counts, so its row is
     * deleted on purpose rather than left without an owner.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHoldingWorkspace(Builder $query): Builder
    {
        return $query->where('status', '!=', DatabaseStatus::Deleting);
    }

    /** Databases of the workspace that count towards its database count. */
    public static function countIn(string $workspaceId): int
    {
        return self::query()->where('workspace_id', $workspaceId)->counted()->count();
    }

    /**
     * Total reported size of the workspace's live databases, in bytes: what
     * the storage quota compares. A database being dropped still takes its
     * space until the node drops it; one never measured counts as 0.
     */
    public static function storageBytesIn(string $workspaceId): int
    {
        return (int) self::query()->where('workspace_id', $workspaceId)->sum('size_bytes');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'engine' => DatabaseEngine::class,
            'status' => DatabaseStatus::class,
            'password' => 'encrypted',
            'size_bytes' => 'integer',
            'size_checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
