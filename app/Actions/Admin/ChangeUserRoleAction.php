<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\UserRole;
use App\Exceptions\Admin\AdminSelfDemotionException;
use App\Exceptions\Admin\LastAdminException;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Promotes a user to admin or demotes an admin, from the admin API or from
 * `artisan user:admin`. Admin rows are locked first, so two admins demoting
 * each other at once cannot leave Sakala without one: the second request sees
 * the first one's result. Both paths write `user.role_changed`.
 */
final class ChangeUserRoleAction
{
    public function handle(User $actor, User $target, UserRole $role, string $reason): User
    {
        if ($role === UserRole::User && $actor->is($target)) {
            throw new AdminSelfDemotionException;
        }

        return $this->change($actor, $target, $role, $reason);
    }

    /**
     * The operator on the server has no account here. The command may demote
     * any admin, including the one whose account the operator uses, but never
     * the last one.
     */
    public function handleFromConsole(User $target, UserRole $role, string $reason): User
    {
        return $this->change(null, $target, $role, $reason);
    }

    private function change(?User $actor, User $target, UserRole $role, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $role, $reason): User {
            $admins = User::query()
                ->where('role', UserRole::Admin)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);

            if ($locked->role === $role) {
                return $locked;
            }

            if ($role === UserRole::User && $admins->reject(fn (int $id): bool => $id === $locked->id)->isEmpty()) {
                throw new LastAdminException;
            }

            // The actor passed the admin check before the lock; a concurrent
            // demotion may have landed since.
            if ($actor !== null && ! $admins->contains($actor->id)) {
                throw new AuthorizationException;
            }

            $previous = $locked->role;
            $locked->update(['role' => $role]);

            $metadata = ['from' => $previous->value, 'to' => $role->value];

            if ($actor !== null) {
                AdminAudit::record($actor, 'user.role_changed', $locked, $reason, $metadata);
            } else {
                AdminAudit::recordFromConsole('user.role_changed', $locked, $reason, $metadata);
            }

            return $locked;
        });
    }
}
