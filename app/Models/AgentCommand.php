<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use Carbon\CarbonImmutable;
use Database\Factories\AgentCommandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property string|null $database_id The managed database a database command acts on.
 * @property string|null $bucket_id The managed bucket a bucket command acts on.
 * @property string|null $valkey_id The managed Valkey store a Valkey command acts on.
 * @property AgentCommandType $type
 * @property AgentCommandStatus $status
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $request_context
 * @property array<string, mixed>|null $response_context
 * @property array<string, mixed>|null $result
 * @property int $attempts
 * @property int $reported_log_bytes Bytes of log lines without a `process`: the deployment's own lines.
 * @property array<string, int>|null $process_log_bytes Bytes of log lines per `process` (ADR-022), each against its own `max_total_bytes`.
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $lease_expires_at
 * @property CarbonImmutable|null $cancel_requested_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable([
    'project_id',
    'deployment_id',
    'database_id',
    'bucket_id',
    'valkey_id',
    'agent_node_id',
    'type',
    'status',
    'payload',
    'request_context',
    'response_context',
    'result',
    'idempotency_key',
    'attempts',
    'reported_log_bytes',
    'process_log_bytes',
    'error_code',
    'error_message',
    'available_at',
    'claimed_at',
    'started_at',
    'completed_at',
    'failed_at',
    'expires_at',
    'lease_expires_at',
    'cancel_requested_at',
])]
class AgentCommand extends Model
{
    /** @use HasFactory<AgentCommandFactory> */
    use HasFactory, HasUuids;

    /** `request_context.reason` of a SleepProject the idle sweep created. */
    public const IDLE_SLEEP_REASON = 'idle';

    /**
     * `error_code` of a lifecycle command whose deployment has no container on
     * the node. Retrying the same command cannot succeed.
     */
    public const WORKLOAD_NOT_FOUND_ERROR = 'runtime_workload_not_found';

    /**
     * Containers a DeployProject asks the node to run: its payload's
     * `replicas`, which is only present above one.
     */
    public function requestedReplicas(): int
    {
        $replicas = $this->payload['replicas'] ?? null;

        return is_int($replicas) ? $replicas : Project::DEFAULT_REPLICAS;
    }

    /**
     * Whether a DeployProject asks the node to run a release command: its
     * payload's `release_command`, which is only present when set. Read from
     * the payload, not the project, so a later change to the project does not
     * move a waiting deployment to a node that cannot run what it carries.
     */
    public function requestsReleaseCommand(): bool
    {
        $command = $this->payload['release_command'] ?? null;

        return is_string($command) && $command !== '';
    }

    /**
     * Whether a DeployProject carries worker definitions (`processes`, ADR-022)
     * and so must stay on a node with AgentNode::WORKER_PROCESSES_CAPABILITY.
     * Read from the payload, like requestsReleaseCommand().
     */
    public function requestsWorkerProcesses(): bool
    {
        $processes = $this->payload['processes'] ?? null;

        return is_array($processes) && $processes !== [];
    }

    /**
     * The node a DeployProject must run on because it joins a Valkey store's
     * network (`valkey.network`, ADR-023): the node of that store. Null
     * without the field, or for a store the API no longer knows. Read from
     * the payload, like requestsWorkerProcesses().
     */
    public function requestedValkeyNodeId(): ?string
    {
        $valkey = $this->payload['valkey'] ?? null;
        $id = ManagedValkey::idFromNetwork(is_array($valkey) ? ($valkey['network'] ?? null) : null);

        if ($id === null) {
            return null;
        }

        /** @var string|null */
        return ManagedValkey::withTrashed()->whereKey($id)->value('node_id');
    }

    /**
     * Whether this SleepProject puts an idle project to sleep, as opposed to
     * suspending it. Suspension sleeps always carry the admin as actor or no
     * actor at all, so an admin who writes "idle" as a reason still suspends.
     */
    public function isIdleSleep(): bool
    {
        return $this->type === AgentCommandType::SleepProject
            && ($this->request_context['reason'] ?? null) === self::IDLE_SLEEP_REASON
            && ($this->request_context['actor_type'] ?? null) === AuditEvent::SYSTEM_ACTOR_TYPE;
    }

    /**
     * Scope a query to commands that may still run.
     *
     * @param  Builder<AgentCommand>  $query
     * @return Builder<AgentCommand>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', AgentCommandStatus::openCases());
    }

    /**
     * Scope a query to the SleepProject commands of the idle sweep: the same
     * test as isIdleSleep(), in SQL.
     *
     * @param  Builder<AgentCommand>  $query
     * @return Builder<AgentCommand>
     */
    public function scopeIdleSleep(Builder $query): Builder
    {
        return $query
            ->where('type', AgentCommandType::SleepProject)
            ->where('request_context->reason', self::IDLE_SLEEP_REASON)
            ->where('request_context->actor_type', AuditEvent::SYSTEM_ACTOR_TYPE);
    }

    /**
     * Scope a query to failed commands that no lifecycle command on the same
     * deployment succeeded after: the failures in a row since the node last
     * proved it holds that deployment's container. A new deployment starts
     * with none, because commands belong to one deployment.
     *
     * @param  Builder<AgentCommand>  $query
     * @return Builder<AgentCommand>
     */
    public function scopeFailedSinceLastLifecycleSuccess(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query
            ->where("{$table}.status", AgentCommandStatus::Failed)
            ->whereNotExists(fn (QueryBuilder $succeeded) => $succeeded
                ->selectRaw('1')
                ->from("{$table} as succeeded")
                ->whereColumn('succeeded.deployment_id', "{$table}.deployment_id")
                ->whereIn('succeeded.type', AgentCommandType::projectLifecycleCases())
                ->where('succeeded.status', AgentCommandStatus::Succeeded)
                ->whereColumn('succeeded.completed_at', '>', "{$table}.failed_at"));
    }

    /**
     * Scope a query to the failed idle sleep that makes the sweep give up on a
     * project: the node said the active deployment's container is not there,
     * and nothing has succeeded on that deployment since. Only meaningful
     * inside a subquery of Project::agentCommands(), because it compares with
     * `projects.active_deployment_id`.
     *
     * @param  Builder<AgentCommand>  $query
     * @return Builder<AgentCommand>
     */
    public function scopeBlockingIdleSleep(Builder $query): Builder
    {
        return $query
            ->idleSleep()
            ->failedSinceLastLifecycleSuccess()
            ->where("{$this->getTable()}.error_code", self::WORKLOAD_NOT_FOUND_ERROR)
            ->whereColumn("{$this->getTable()}.deployment_id", 'projects.active_deployment_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /** @return BelongsTo<ManagedDatabase, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(ManagedDatabase::class, 'database_id')->withTrashed();
    }

    /** @return BelongsTo<ManagedBucket, $this> */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(ManagedBucket::class, 'bucket_id')->withTrashed();
    }

    /** @return BelongsTo<ManagedValkey, $this> */
    public function valkey(): BelongsTo
    {
        return $this->belongsTo(ManagedValkey::class, 'valkey_id')->withTrashed();
    }

    /** @return BelongsTo<AgentNode, $this> */
    public function agentNode(): BelongsTo
    {
        return $this->belongsTo(AgentNode::class);
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

    /** @return HasMany<AgentCommandReport, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(AgentCommandReport::class);
    }

    /** @return HasOne<ProjectControlRequest, $this> */
    public function controlRequests(): HasOne
    {
        return $this->hasOne(ProjectControlRequest::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AgentCommandType::class,
            'status' => AgentCommandStatus::class,
            'payload' => 'array',
            'request_context' => 'array',
            'response_context' => 'array',
            'result' => 'array',
            'attempts' => 'integer',
            'reported_log_bytes' => 'integer',
            'process_log_bytes' => 'array',
            'available_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
        ];
    }
}
