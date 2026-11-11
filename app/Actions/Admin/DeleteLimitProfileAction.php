<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\Admin\LimitProfileInUseException;
use App\Models\LimitProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a profile no workspace uses. The row lock conflicts with the shared
 * lock attaching a profile takes, so a profile cannot gain a workspace between
 * the check and the delete; the foreign key is the last line.
 */
final class DeleteLimitProfileAction
{
    public function handle(User $actor, LimitProfile $profile, string $reason): void
    {
        DB::transaction(function () use ($actor, $profile, $reason): void {
            /** @var LimitProfile $locked */
            $locked = LimitProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $workspaces = Workspace::query()->where('limit_profile_id', $locked->id)->count();

            if ($workspaces > 0) {
                throw new LimitProfileInUseException($workspaces);
            }

            AdminAudit::record($actor, 'limit_profile.deleted', $locked, $reason, ['name' => $locked->name]);

            $locked->delete();
        });
    }
}
