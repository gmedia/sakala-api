<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DatabaseDeployRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A managed database attached to a project of the same workspace. The
 * project's next deployment receives the connection variables.
 *
 * An attachment made together with its database may carry a request to
 * deploy the project once the database is ready (`deploy_when_ready`):
 * pending while `deploy_requested_at` is set and `deploy_resolved_at` is not,
 * then resolved once with a deployment or a skip reason. It goes away with
 * the attachment.
 *
 * @property int $id
 * @property string $database_id
 * @property string $project_id
 * @property int|null $created_by
 * @property int|null $deploy_requested_by Who asked for the deployment; the deployment runs as them.
 * @property CarbonImmutable|null $deploy_requested_at
 * @property string|null $deploy_deployment_id
 * @property string|null $deploy_skipped_reason A DatabaseRedeploySkipReason value.
 * @property CarbonImmutable|null $deploy_resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'database_id',
    'project_id',
    'created_by',
    'deploy_requested_by',
    'deploy_requested_at',
    'deploy_deployment_id',
    'deploy_skipped_reason',
    'deploy_resolved_at',
])]
class DatabaseAttachment extends Model
{
    /** Columns of the deploy request, also read through Project::databases() as pivot columns. */
    public const DEPLOY_REQUEST_COLUMNS = [
        'deploy_requested_by',
        'deploy_requested_at',
        'deploy_deployment_id',
        'deploy_skipped_reason',
        'deploy_resolved_at',
    ];

    /** Prefix of the idempotency key of the deployment, followed by the attachment ID. */
    private const DEPLOY_IDEMPOTENCY_PREFIX = 'database-ready:';

    /** @return BelongsTo<ManagedDatabase, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(ManagedDatabase::class, 'database_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deployRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deploy_requested_by');
    }

    /**
     * Attachments whose deploy request is not resolved yet.
     *
     * @param  Builder<DatabaseAttachment>  $query
     * @return Builder<DatabaseAttachment>
     */
    public function scopeDeployPending(Builder $query): Builder
    {
        return $query->whereNotNull('deploy_requested_at')->whereNull('deploy_resolved_at');
    }

    /** Null without a deploy request. */
    public function deployRequestStatus(): ?DatabaseDeployRequestStatus
    {
        return match (true) {
            $this->deploy_requested_at === null => null,
            $this->deploy_resolved_at === null => DatabaseDeployRequestStatus::Waiting,
            $this->deploy_skipped_reason !== null => DatabaseDeployRequestStatus::Skipped,
            default => DatabaseDeployRequestStatus::Started,
        };
    }

    public function isDeployPending(): bool
    {
        return $this->deployRequestStatus() === DatabaseDeployRequestStatus::Waiting;
    }

    /** One deployment per attachment, whoever runs the job and however often. */
    public function deployIdempotencyKey(): string
    {
        return self::DEPLOY_IDEMPOTENCY_PREFIX.$this->id;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'deploy_requested_at' => 'immutable_datetime',
            'deploy_resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
