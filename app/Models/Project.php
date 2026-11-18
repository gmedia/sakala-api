<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectControlAction;
use App\Enums\ProjectInspectionStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Support\Project\DeploymentConfigFingerprint;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
 * @property string|null $release_command Run once per deployment before the new version takes traffic; only placed on nodes with AgentNode::RELEASE_COMMAND_CAPABILITY.
 * @property string|null $root_directory
 * @property int $replicas Containers the next deployment runs, behind the node's router. Applies from the next deployment.
 * @property string|null $region Region the project runs in; null follows the workspace profile's default_region (PilotRuntimeLimitService::regionFor()).
 * @property CarbonImmutable|null $config_changed_at Last deploy-relevant change; only read for an active deployment without a config_fingerprint.
 * @property string|null $environment_fingerprint DeploymentConfigFingerprint::forEnvironment() of the stored variables; null until the first variable change or deployment after it was introduced.
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
    'release_command',
    'root_directory',
    'replicas',
    'region',
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
#[Hidden(['environment_fingerprint'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** The `PORT` a workload gets while no port was detected for the project. */
    public const DEFAULT_CONTAINER_PORT = 3000;

    /** Attribute that withIdleSleepBlocked() adds. */
    public const IDLE_SLEEP_BLOCKED_ATTRIBUTE = 'idle_sleep_blocked';

    /**
     * Replicas of a project unless its owner asks for more, and the fewest it
     * runs. Also the most a workspace allows when its profile sets no
     * `max_replicas`.
     */
    public const DEFAULT_REPLICAS = 1;

    /** Attributes that scopeHoldingContainers() adds. */
    public const SERVING_REPLICAS_ATTRIBUTE = 'serving_replicas';

    public const DEPLOYING_REPLICAS_ATTRIBUTE = 'deploying_replicas';

    /**
     * The column default, so a project answered right after it is created
     * sends 1 instead of null.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'replicas' => self::DEFAULT_REPLICAS,
    ];

    /**
     * The port the agent routes to and hands to the workload as `PORT`.
     */
    public function effectivePort(): int
    {
        return $this->configured_port ?? $this->detected_port ?? self::DEFAULT_CONTAINER_PORT;
    }

    /**
     * Whether the next deployment runs a release command, and so needs a node
     * with AgentNode::RELEASE_COMMAND_CAPABILITY.
     */
    public function hasReleaseCommand(): bool
    {
        return $this->release_command !== null && $this->release_command !== '';
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
        return $this->liveDeployment()?->container_port;
    }

    /**
     * Replicas the deployment that serves right now runs: what its node
     * reported, or what it was asked for until the node reports. Null when
     * nothing serves, like servingPort().
     */
    public function servingReplicas(): ?int
    {
        $deployment = $this->liveDeployment();

        return $deployment === null ? null : ($deployment->replicas_desired ?? $deployment->replicas);
    }

    /**
     * Replicas of the serving deployment that pass the router's health check,
     * from the node's heartbeat. Null when nothing serves or the node has not
     * reported yet (older agents never do).
     */
    public function servingReplicasReady(): ?int
    {
        return $this->liveDeployment()?->replicas_ready;
    }

    /**
     * Whether the project holds running container slots: it has an active
     * deployment that is not asleep. The same test as scopeServing().
     */
    public function isServing(): bool
    {
        return $this->active_deployment_id !== null && $this->runtime_status !== RuntimeStatus::Sleeping;
    }

    /**
     * Running containers the project holds for its workspace's limit, from a
     * query that used scopeHoldingContainers(): the replicas of its serving
     * deployment, or of a deployment in progress when that one runs more, since
     * that is what the project runs once it succeeds. A rolling deployment
     * briefly runs both sets on the node; the workspace limit counts the
     * steady state.
     */
    public function heldContainers(): int
    {
        $serving = $this->isServing() ? (int) $this->getAttribute(self::SERVING_REPLICAS_ATTRIBUTE) : 0;

        return max($serving, (int) $this->getAttribute(self::DEPLOYING_REPLICAS_ATTRIBUTE));
    }

    /** The active deployment while it serves traffic: not crashed, not asleep. */
    private function liveDeployment(): ?Deployment
    {
        if (! $this->isServing() || $this->runtime_status === RuntimeStatus::Crashed) {
            return null;
        }

        return $this->activeDeployment;
    }

    /**
     * Whether the serving deployment was created with another deploy-relevant
     * configuration than the project has now (DeploymentConfigFingerprint), so
     * only a new deployment applies it. Reverting a change makes it false again.
     *
     * A deployment created before fingerprints existed falls back to
     * timestamps: the last deploy-relevant change against its `created_at`.
     * They have second precision; a tie counts as changed, because a needless
     * redeploy hint costs less than a missed one.
     */
    public function requiresRedeploy(): bool
    {
        if ($this->active_deployment_id === null) {
            return false;
        }

        $deployment = $this->activeDeployment;

        if ($deployment === null) {
            return false;
        }

        if ($deployment->config_fingerprint !== null) {
            return ! hash_equals($deployment->config_fingerprint, $this->deploymentConfigFingerprint());
        }

        $deployedAt = $deployment->created_at;

        return $this->config_changed_at !== null
            && $deployedAt !== null
            && $this->config_changed_at->greaterThanOrEqualTo($deployedAt);
    }

    /** The deploy-relevant configuration the next deployment gets, as DeploymentConfigFingerprint::forProject(). */
    public function deploymentConfigFingerprint(): string
    {
        return DeploymentConfigFingerprint::forProject($this);
    }

    /**
     * Recompute `environment_fingerprint` from the stored variables, without
     * saving. Call it under the project row lock, so a concurrent variable
     * change cannot leave a stale value.
     */
    public function refreshEnvironmentFingerprint(): void
    {
        $this->environment_fingerprint = DeploymentConfigFingerprint::forEnvironment(
            $this->environmentVariables()->get(),
        );
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
            'replicas' => 'integer',
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
     * Scope a query to projects that hold running container slots or are being
     * deployed and will, so two deployments racing for the last slot cannot
     * both pass, with what heldContainers() reads: the replicas of the active
     * deployment and the most replicas of a deployment in progress. One query
     * for the whole workspace.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeHoldingContainers(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $holding) => $holding
                ->where(fn (Builder $serving) => $serving->serving())
                ->orWhereHas('deployments', self::activeDeploymentsOf(...)))
            ->addSelect([
                self::SERVING_REPLICAS_ATTRIBUTE => Deployment::query()
                    ->select('replicas')
                    ->whereColumn('deployments.id', 'projects.active_deployment_id')
                    ->limit(1),
            ])
            ->withMax(['deployments as '.self::DEPLOYING_REPLICAS_ATTRIBUTE => self::activeDeploymentsOf(...)], 'replicas');
    }

    /**
     * Deployments in progress, for the subqueries of scopeHoldingContainers().
     *
     * @param  Builder<Deployment>  $deployments
     * @return Builder<Deployment>
     */
    private static function activeDeploymentsOf(Builder $deployments): Builder
    {
        return $deployments->active();
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
