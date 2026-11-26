<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Models\ValkeyAttachment;
use App\Support\Valkey\ValkeyAudit;
use Illuminate\Support\Facades\DB;

/**
 * Detaches a Valkey store from a project. The data stays; the project's next
 * deployment no longer joins the store's network or receives the variables,
 * so `redeploy_required` turns true while the running one still has them.
 * Its running containers stay on the store's network until then, so the
 * store cannot be deleted before that redeploy (`valkey_in_use`).
 */
final class DetachValkeyAction
{
    public function handle(User $actor, ManagedValkey $valkey, Project $project): void
    {
        DB::transaction(function () use ($actor, $valkey, $project): void {
            ManagedValkey::withTrashed()->whereKey($valkey->id)->lockForUpdate()->firstOrFail();
            /** @var Project $lockedProject */
            $lockedProject = Project::withTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            ValkeyAttachment::query()
                ->where('valkey_id', $valkey->id)
                ->where('project_id', $lockedProject->id)
                ->firstOrFail()
                ->delete();

            $lockedProject->refreshValkeyFingerprint();
            $lockedProject->save();

            ValkeyAudit::record($actor, ValkeyAudit::DETACHED, $valkey, [
                'project_id' => $lockedProject->id,
                'reason' => ValkeyAudit::DETACH_REASON_USER,
            ]);
        });
    }
}
