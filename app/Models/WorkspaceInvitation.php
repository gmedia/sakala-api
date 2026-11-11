<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation. The plain token only ever exists in the email; the row keeps
 * its SHA-256 hash. Answering, revoking, or replacing it closes the row rather
 * than deleting it; workspaces:prune-invitations deletes it later.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property WorkspaceRole $role
 * @property WorkspaceInvitationStatus $status
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['workspace_id', 'email', 'role', 'status', 'token_hash', 'invited_by', 'expires_at', 'closed_at'])]
#[Hidden(['token_hash'])]
class WorkspaceInvitation extends Model
{
    use HasUuids;

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'pending',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === WorkspaceInvitationStatus::Pending;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', WorkspaceInvitationStatus::Pending);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => WorkspaceInvitationStatus::class,
            'expires_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
