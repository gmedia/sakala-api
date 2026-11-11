<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Sets how many teams a user may own, or null for the configured default.
 * Under the user row lock that team creation also takes, so a team created
 * at the same moment is counted against the old or the new cap, never
 * neither. A cap below the teams already owned only stops new ones.
 */
final class SetUserTeamLimitAction
{
    public function handle(User $actor, User $target, ?int $maxOwnedTeams, string $reason): User
    {
        return DB::transaction(function () use ($actor, $target, $maxOwnedTeams, $reason): User {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $previous = $locked->max_owned_teams;

            if ($previous !== $maxOwnedTeams) {
                $locked->forceFill(['max_owned_teams' => $maxOwnedTeams])->save();

                AdminAudit::record($actor, 'user.max_owned_teams_changed', $locked, $reason, [
                    'from' => $previous,
                    'to' => $maxOwnedTeams,
                ]);
            }

            return $locked;
        });
    }
}
