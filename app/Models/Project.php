<?php

declare(strict_types=1);

namespace App\Models;

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
    'last_deployed_at',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasUuids, SoftDeletes;

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'runtime_status' => RuntimeStatus::class,
            'detected_port' => 'integer',
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
