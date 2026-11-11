<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\LimitProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspace\WorkspaceAudit;
use Illuminate\Support\Facades\DB;

/**
 * Attaches a limit profile to a workspace, or detaches it with null so the
 * workspace falls back to the default for its type. Limits already reached are
 * not undone: a lower limit only stops new projects and deployments. The admin
 * API calls this; nothing in the app API can.
 */
final class AssignLimitProfileAction
{
    public function handle(?User $actor, Workspace $workspace, ?LimitProfile $profile): Workspace
    {
        return DB::transaction(function () use ($actor, $workspace, $profile): Workspace {
            $locked = WorkspaceOwnership::lock($workspace);
            $previous = $locked->limit_profile_id;

            if ($previous !== $profile?->id) {
                $locked->update(['limit_profile_id' => $profile?->id]);

                WorkspaceAudit::record($actor, 'workspace.limit_profile_changed', $locked, [
                    'from_limit_profile_id' => $previous,
                    'to_limit_profile_id' => $profile?->id,
                ]);
            }

            return $locked->refresh();
        });
    }
}
