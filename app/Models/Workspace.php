<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owns projects. Every user has exactly one personal workspace; a team is a
 * workspace of type `team` with members in roles. Limits count per workspace.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property WorkspaceType $type
 * @property int|null $personal_user_id
 * @property string|null $limit_profile_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property WorkspaceMember|null $membership Present when loaded through User::workspaces().
 */
#[Fillable(['name', 'slug', 'type', 'personal_user_id', 'limit_profile_id'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUuids;

    public function isPersonal(): bool
    {
        return $this->type === WorkspaceType::Personal;
    }

    /**
     * The user's role here, read fresh: access is lost the moment a membership
     * changes, so this is never cached across requests.
     */
    public function roleOf(User $user): ?WorkspaceRole
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $this->id)
            ->where('user_id', $user->id)
            ->first(['id', 'role'])
            ?->role;
    }

    /**
     * People who can act on the workspace's projects: owners and members. They
     * receive project notifications; viewers only read.
     *
     * @return Collection<int, User>
     */
    public function projectManagers(): Collection
    {
        return User::query()
            ->whereIn('id', WorkspaceMember::query()
                ->select('user_id')
                ->where('workspace_id', $this->id)
                ->whereIn('role', WorkspaceRole::projectManagerValues()))
            ->orderBy('id')
            ->get();
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return BelongsToMany<User, $this, WorkspaceMember, 'membership'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->using(WorkspaceMember::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<WorkspaceInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(WorkspaceInvitation::class);
    }

    /** @return BelongsTo<LimitProfile, $this> */
    public function limitProfile(): BelongsTo
    {
        return $this->belongsTo(LimitProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function personalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'personal_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WorkspaceType::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
