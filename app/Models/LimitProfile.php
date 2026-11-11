<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LimitProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable resource limits attached to workspaces. Limits count per workspace,
 * never per member.
 *
 * @property string $id
 * @property string $name
 * @property int $max_projects
 * @property int $max_active_containers
 * @property int $max_active_deployments
 * @property int $max_memory_mb
 * @property int $max_cpu_millis
 * @property int|null $max_builds_per_hour Reserved for the build rate limit (#124); not enforced yet.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'max_projects',
    'max_active_containers',
    'max_active_deployments',
    'max_memory_mb',
    'max_cpu_millis',
    'max_builds_per_hour',
])]
class LimitProfile extends Model
{
    /** @use HasFactory<LimitProfileFactory> */
    use HasFactory, HasUuids;

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_projects' => 'integer',
            'max_active_containers' => 'integer',
            'max_active_deployments' => 'integer',
            'max_memory_mb' => 'integer',
            'max_cpu_millis' => 'integer',
            'max_builds_per_hour' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
