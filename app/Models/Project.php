<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectControlAction;
use App\Enums\ProjectInspectionStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property int|null $user_id The creator. Null once that account is deleted.
 * @property string|null $workspace_id The owner. Null only on a soft-deleted project whose workspace is gone.
 * @property string|null $github_installation_id
 * @property int|null $github_repository_id
 * @property string $name
 * @property string $slug
 * @property string $repository_provider
 * @property string|null $thumbnail_url
 * @property string $repository_url
 * @property string|null $repository_full_name
 * @property string $branch
 * @property string $default_domain
 * @property ProjectStatus $status
 * @property RuntimeStatus $runtime_status
 * @property int|null $detected_port
 * @property int|null $configured_port
 * @property string|null $build_command
 * @property string|null $start_command
 * @property string|null $root_directory
 * @property CarbonImmutable|null $config_changed_at
 * @property bool $auto_deploy
 * @property string|null $active_deployment_id
 * @property array<string, mixed>|null $inspection
 * @property ProjectInspectionStatus|null $inspection_status
 * @property string|null $inspection_error_code
 * @property CarbonImmutable|null $inspected_at
 * @property CarbonImmutable|null $last_deployed_at
 * @property CarbonImmutable|null $last_request_at When the router last served a request for the active deployment, from agent heartbeats.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id',
    'workspace_id',
    'github_installation_id',
    'github_repository_id',
    'name',
    'slug',
    'repository_provider',
    'thumbnail_url',
    'repository_url',
    'repository_full_name',
    'branch',
    'default_domain',
    'status',
    'runtime_status',
    'detected_port',
    'configured_port',
    'build_command',
    'start_command',
    'root_directory',
    'config_changed_at',
    'auto_deploy',
    'active_deployment_id',
    'inspection',
    'inspection_status',
    'inspection_error_code',
    'inspected_at',
    'last_deployed_at',
    'last_request_at',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** The `PORT` a workload gets while no port was detected for the project. */
    public const DEFAULT_CONTAINER_PORT = 3000;

    /** Attribute that withIdleSleepBlocked() adds. */
    public const IDLE_SLEEP_BLOCKED_ATTRIBUTE = 'idle_sleep_blocked';

    /**
     * The port the agent routes to and hands to the workload as `PORT`.
     */
    public function effectivePort(): int
    {
        return $this->configured_port ?? $this->detected_port ?? self::DEFAULT_CONTAINER_PORT;
    }

    /**
     * Where people reach the project, for this installation. The console must
     * not assemble it: local and self-hosted runtimes differ in scheme and port.
     */
    public function publicUrl(): string
    {
        $scheme = (string) config('sakala.project.url_scheme', 'https');
        $port = config('sakala.project.url_port');

        return "{$scheme}://{$this->default_domain}".(filled($port) ? ":{$port}" : '');
    }

    /**
     * The port live traffic reaches right now, which can differ from
     * effectivePort() until the next deployment. Null when nothing serves.
     */
    public function servingPort(): ?int
    {
        if (
            $this->active_deployment_id === null
            || $this->runtime_status === RuntimeStatus::Crashed
            || $this->runtime_status === RuntimeStatus::Sleeping
        ) {
            return null;
        }

        return $this->activeDeployment?->container_port;
    }

    /**
     * Whether the serving deployment predates the last change to environment
     * variables or build configuration. A deployment snapshots both when it is
     * created, so only a new deployment applies the change. Timestamps have
     * second precision; a tie counts as changed, because a needless redeploy
     * hint costs less than a missed one.
     */
    public function requiresRedeploy(): bool
    {
        if ($this->config_changed_at === null || $this->active_deployment_id === null) {
            return false;
        }

        $deployedAt = $this->activeDeployment?->created_at;

        return $deployedAt !== null && $this->config_changed_at->greaterThanOrEqualTo($deployedAt);
    }

    /**
     * The deployment currently serving the project. Survives failed
     * redeploys; cleared when the workload is stopped on purpose.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function activeDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'active_deployment_id');
    }

    /**
     * The creator. Ownership and access go through workspace().
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<GithubInstallation, $this> */
    public function githubInstallation(): BelongsTo
    {
        return $this->belongsTo(GithubInstallation::class);
    }

    /** @return HasMany<EnvironmentVariable, $this> */
    public function environmentVariables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * The newest attempt, whatever its outcome. `sequence` is allocated under
     * the project lock, so unlike a timestamp it cannot tie. Not the same as
     * activeDeployment(), which is what serves traffic.
     *
     * Not latestOfMany(): it always adds MAX(id) as a tie-break, and PostgreSQL
     * has no max(uuid). SQLite accepts it, so only the PostgreSQL job would notice.
     * The limit applies per project when eager loaded.
     *
     * @return HasOne<Deployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->orderByDesc('sequence')->limit(1);
    }

    /** @return HasMany<AgentCommand, $this> */
    public function agentCommands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /** @return HasMany<Feedback, $this> */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /** @return HasMany<ProjectControlRequest, $this> */
    public function controlRequests(): HasMany
    {
        return $this->hasMany(ProjectControlRequest::class);
    }

    /**
     * The suspension in force while the project is suspended, and the last one
     * lifted otherwise, so read `status` first. Other control actions carry
     * reasons for admins only and stay out. The limit applies per project when
     * eager loaded, as on latestDeployment().
     *
     * @return HasOne<ProjectControlRequest, $this>
     */
    public function latestSuspendRequest(): HasOne
    {
        return $this->hasOne(ProjectControlRequest::class)
            ->where('action', ProjectControlAction::Suspend)
            ->orderByDesc('id')
            ->limit(1);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'runtime_status' => RuntimeStatus::class,
            'detected_port' => 'integer',
            'configured_port' => 'integer',
            'config_changed_at' => 'immutable_datetime',
            'auto_deploy' => 'boolean',
            'inspection' => 'array',
            'inspection_status' => ProjectInspectionStatus::class,
            'inspected_at' => 'immutable_datetime',
            'github_repository_id' => 'integer',
            'last_deployed_at' => 'immutable_datetime',
            'last_request_at' => 'immutable_datetime',
        ];
    }

    /**
     * Scope a query to projects whose active deployment holds a running
     * container slot. A sleeping project keeps its active deployment but runs
     * nothing.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeServing(Builder $query): Builder
    {
        return $query
            ->whereNotNull('active_deployment_id')
            ->where('runtime_status', '!=', RuntimeStatus::Sleeping);
    }

    /**
     * Scope a query to projects the idle sweep gave up on (`$blocked`), or to
     * the others: a node reported that the active deployment's container is
     * missing, and no lifecycle command succeeded on it since. A new
     * deployment, or a wake or restart that succeeds, lifts it.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeIdleSleepBlocked(Builder $query, bool $blocked = true): Builder
    {
        return $blocked
            ? $query->whereHas('agentCommands', self::blockingIdleSleepOf(...))
            : $query->whereDoesntHave('agentCommands', self::blockingIdleSleepOf(...));
    }

    /**
     * Add the `idle_sleep_blocked` attribute that isIdleSleepBlocked() reads,
     * so a list costs one subquery instead of one query per project.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeWithIdleSleepBlocked(Builder $query): Builder
    {
        return $query->withExists([
            'agentCommands as '.self::IDLE_SLEEP_BLOCKED_ATTRIBUTE => self::blockingIdleSleepOf(...),
        ]);
    }

    /**
     * Whether the idle sweep gave up on this project (see
     * scopeIdleSleepBlocked()). Asks the database unless the query used
     * withIdleSleepBlocked().
     */
    public function isIdleSleepBlocked(): bool
    {
        if (array_key_exists(self::IDLE_SLEEP_BLOCKED_ATTRIBUTE, $this->getAttributes())) {
            return (bool) $this->getAttribute(self::IDLE_SLEEP_BLOCKED_ATTRIBUTE);
        }

        return self::query()->whereKey($this->getKey())->idleSleepBlocked()->exists();
    }

    /**
     * The commands that block idle sleep, for the subqueries of the scopes above.
     *
     * @param  Builder<AgentCommand>  $commands
     * @return Builder<AgentCommand>
     */
    private static function blockingIdleSleepOf(Builder $commands): Builder
    {
        return $commands->blockingIdleSleep();
    }

    /**
     * Scope a query to only include projects accessible by the given user.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('workspace_id', WorkspaceMember::query()
            ->select('workspace_id')
            ->where('user_id', $user->id));
    }
}
