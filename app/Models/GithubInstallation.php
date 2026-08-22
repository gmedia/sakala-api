<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GithubInstallationStatus;
use Database\Factories\GithubInstallationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $user_id
 * @property int $github_installation_id
 * @property GithubInstallationStatus $status
 */
#[Fillable(['user_id', 'github_installation_id', 'account_id', 'account_login', 'account_type', 'repository_selection', 'permissions', 'status', 'suspended_at', 'removed_at'])]
class GithubInstallation extends Model
{
    /** @use HasFactory<GithubInstallationFactory> */
    use HasFactory, HasUuids;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'status' => GithubInstallationStatus::class, 'suspended_at' => 'immutable_datetime', 'removed_at' => 'immutable_datetime'];
    }
}
