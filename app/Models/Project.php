<?php

declare(strict_types=1);

namespace App\Models;

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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property int $user_id
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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id',
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
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** The `PORT` a workload gets while no port was detected for the project. */
    public const DEFAULT_CONTAINER_PORT = 3000;

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
        if ($this->active_deployment_id === null || $this->runtime_status === RuntimeStatus::Crashed) {
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        ];
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

        return $query->where('user_id', $user->id);
    }
}
