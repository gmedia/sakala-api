<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectProcessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worker process of a project (ADR-022): containers of the deployment's
 * image and variables that run `command` with `sh -c`, next to `web`, without
 * a route. Changes apply from the next deployment, which records them in its
 * DeployProject payload; only nodes with AgentNode::WORKER_PROCESSES_CAPABILITY
 * receive them.
 *
 * @property string $id
 * @property string $project_id
 * @property string $name Lowercase letters, digits, and dashes, starting with a letter; `web`, `release`, and `job` are reserved.
 * @property string $command
 * @property int $replicas Containers of this process; 0 keeps the definition and runs nothing.
 * @property int|null $memory_mb Per container; null follows the deployment's.
 * @property int|null $cpu_millis Per container; null follows the deployment's.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'name',
    'command',
    'replicas',
    'memory_mb',
    'cpu_millis',
])]
class ProjectProcess extends Model
{
    /** @use HasFactory<ProjectProcessFactory> */
    use HasFactory, HasUuids;

    /** Definitions a project may hold, replicas or not: configuration, not a quota (the agent's own maximum). */
    public const MAX_PER_PROJECT = 5;

    /** Longest name, in characters. */
    public const MAX_NAME_LENGTH = 30;

    /** What a name may look like; reserved names are refused separately. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /** The process behind the project's route. */
    public const WEB_PROCESS = 'web';

    /** The process of a deployment's release command. */
    public const RELEASE_PROCESS = 'release';

    /** The process of a RunJob command, as log lines name it. */
    public const JOB_PROCESS = 'job';

    /** Names the platform uses for its own processes. */
    public const RESERVED_NAMES = [self::WEB_PROCESS, self::RELEASE_PROCESS, self::JOB_PROCESS];

    /** Longest command, in characters, as for the release command. */
    public const MAX_COMMAND_LENGTH = 1000;

    /** Replicas of a definition created without any. */
    public const DEFAULT_REPLICAS = 1;

    /** @var array<string, int> */
    protected $attributes = [
        'replicas' => self::DEFAULT_REPLICAS,
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'replicas' => 'integer',
            'memory_mb' => 'integer',
            'cpu_millis' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
