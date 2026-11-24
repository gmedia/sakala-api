<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeploymentBuildFallbackReason;
use App\Enums\DeploymentBuildMode;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\FinalizationDeferredReason;
use Carbon\CarbonImmutable;
use Database\Factories\DeploymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $project_id
 * @property int|null $requested_by
 * @property string|null $agent_node_id
 * @property int $sequence
 * @property DeploymentStatus $status
 * @property DeploymentTrigger $trigger
 * @property string|null $triggered_by_name GitHub name of the pusher for `webhook` deployments (`pusher.name`, else `sender.login`). Null otherwise and for deployments from before it was recorded.
 * @property string|null $rollback_target_deployment_id The deployment a `rollback` deployment went back to. Null otherwise, or once that deployment is deleted.
 * @property string|null $settings_source_deployment_id The deployment whose recorded settings this one ran with (DeploymentSettingsData::recordedBy()). Null when it ran with the project's settings.
 * @property string $branch
 * @property string|null $commit_sha
 * @property string|null $commit_message
 * @property string|null $image_reference
 * @property DeploymentBuildMode $build_mode `reused` when planned to start an earlier deployment's image (PlanDeploymentBuildAction), `built` otherwise and after the agent fell back to a build.
 * @property string|null $image_source_deployment_id The deployment that built the image this one asked to reuse; kept after a fallback. Null when reuse was never planned, or once that deployment is deleted.
 * @property DeploymentBuildFallbackReason|null $build_fallback_reason Why the agent built a deployment planned as reuse. Null otherwise.
 * @property int|null $container_port
 * @property int $replicas Containers this deployment was asked to run, fixed at creation like `container_port`.
 * @property int $worker_containers Worker containers it runs next to its replicas (ADR-022), fixed at creation: the sum of the replicas of the `processes` in its payload.
 * @property array<string, array<string, mixed>>|null $crashed_processes Worker processes the node reports as crashing, keyed by name, with the heartbeat's crash detail and `since`. Null when none.
 * @property int|null $replicas_desired Containers the node runs for it, from heartbeats. Null until reported.
 * @property int|null $replicas_ready Of those, how many pass the router's health check, from heartbeats. Null until reported.
 * @property string|null $config_fingerprint DeploymentConfigFingerprint::forProject() of the project when this deployment was created. Null for deployments created before it existed.
 * @property string|null $build_fingerprint DeploymentConfigFingerprint::forBuild() when this deployment was created, classified once the inspection lands when it was created without one (ClassifyFallbackBuildFingerprintsAction). Null for deployments created before image reuse, which are never reused.
 * @property string|null $idempotency_key
 * @property array{
 *     memory_mb: int|null,
 *     cpu_millis: int|null,
 *     pids_limit: int|null
 * }|null $requested_resources
 * @property array{
 *     resources: array{
 *         memory_mb: int,
 *         cpu_millis: int,
 *         pids_limit: int
 *     },
 *     timeouts: array{
 *         build_timeout_seconds: int,
 *         start_timeout_seconds: int,
 *         command_timeout_seconds: int
 *     },
 *     log_bounds: array{
 *         max_line_length: int,
 *         max_batch_lines: int,
 *         max_total_bytes: int
 *     }
 * }|null $effective_resources
 * @property array<string, mixed>|null $applied_resources
 * @property bool $finalization_deferred
 * @property FinalizationDeferredReason|null $finalization_deferred_reason
 * @property string|null $failure_code
 * @property string|null $failure_summary
 * @property string|null $failure_detail
 * @property DeploymentStatus|null $failure_stage
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable|null $awaiting_inspection_until
 * @property CarbonImmutable|null $crash_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([

    'project_id',
    'requested_by',
    'agent_node_id',
    'idempotency_key',
    'sequence',
    'status',
    'realtime_sequence',
    'trigger',
    'triggered_by_name',
    'rollback_target_deployment_id',
    'settings_source_deployment_id',
    'branch',
    'commit_sha',
    'commit_message',
    'image_reference',
    'build_mode',
    'image_source_deployment_id',
    'build_fallback_reason',
    'container_port',
    'replicas',
    'worker_containers',
    'crashed_processes',
    'replicas_desired',
    'replicas_ready',
    'config_fingerprint',
    'build_fingerprint',
    'requested_resources',
    'effective_resources',
    'applied_resources',
    'finalization_deferred',
    'finalization_deferred_reason',
    'failure_code',
    'failure_summary',
    'failure_detail',
    'failure_stage',
    'started_at',
    'finished_at',
    'cancelled_at',
    'cancel_requested_at',
    'awaiting_inspection_until',
    'crash_notified_at',
])]
#[Hidden(['config_fingerprint', 'build_fingerprint'])]
class Deployment extends Model
{
    /** @use HasFactory<DeploymentFactory> */
    use HasFactory, HasUuids;

    /** Bound (in characters) of the failure detail shown to the console. */
    public const MAX_FAILURE_DETAIL_LENGTH = 500;

    /** Bound (in characters) of the stored commit subject; the column is varchar(500). */
    public const MAX_COMMIT_MESSAGE_LENGTH = 500;

    /** Bound (in characters) of `triggered_by_name`; the column is varchar(255). */
    public const MAX_TRIGGERED_BY_NAME_LENGTH = 255;

    /**
     * The column defaults, so a deployment answered right after it is created
     * sends `false`, 1, and `built` instead of null.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'finalization_deferred' => false,
        'replicas' => Project::DEFAULT_REPLICAS,
        'worker_containers' => 0,
        'build_mode' => DeploymentBuildMode::Built->value,
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<AgentNode, $this> */
    public function agentNode(): BelongsTo
    {
        return $this->belongsTo(AgentNode::class);
    }

    /**
     * The deployment that built the image this one asked to reuse.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function imageSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'image_source_deployment_id');
    }

    /**
     * The deployment a `rollback` deployment went back to.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function rollbackTarget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rollback_target_deployment_id');
    }

    /**
     * The deployment whose recorded settings this one ran with.
     *
     * @return BelongsTo<Deployment, $this>
     */
    public function settingsSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'settings_source_deployment_id');
    }

    /**
     * The deployment that built the image this one runs: itself when it
     * built, its source when it reused (so chains resolve to the builder),
     * and null when it reused and that source is gone.
     */
    public function imageBuilderId(): ?string
    {
        return $this->build_mode === DeploymentBuildMode::Reused
            ? $this->image_source_deployment_id
            : $this->id;
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /** @return HasMany<DeploymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DeploymentEvent::class);
    }

    /** @return HasMany<DeploymentLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(DeploymentLog::class);
    }

    /** @return HasMany<Feedback, $this> */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Scope a query to only include active (non-terminal) deployments.
     *
     * @param  Builder<Deployment>  $query
     * @return Builder<Deployment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', DeploymentStatus::activeCases());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'status' => DeploymentStatus::class,
            'realtime_sequence' => 'integer',
            'trigger' => DeploymentTrigger::class,
            'build_mode' => DeploymentBuildMode::class,
            'build_fallback_reason' => DeploymentBuildFallbackReason::class,
            'container_port' => 'integer',
            'replicas' => 'integer',
            'worker_containers' => 'integer',
            'replicas_desired' => 'integer',
            'replicas_ready' => 'integer',
            'requested_resources' => 'array',
            'effective_resources' => 'array',
            'applied_resources' => 'array',
            'finalization_deferred' => 'boolean',
            'finalization_deferred_reason' => FinalizationDeferredReason::class,
            'failure_stage' => DeploymentStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'awaiting_inspection_until' => 'immutable_datetime',
            'crash_notified_at' => 'immutable_datetime',
            'crashed_processes' => 'array',
        ];
    }
}
