<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Models\BucketAttachment;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Support\Bucket\BucketAudit;
use Illuminate\Support\Facades\DB;

/**
 * Detaches a bucket from a project. The files stay; the project's next
 * deployment no longer receives the variables, so `redeploy_required` turns
 * true while the running one still has them.
 */
final class DetachBucketAction
{
    public function handle(User $actor, ManagedBucket $bucket, Project $project): void
    {
        DB::transaction(function () use ($actor, $bucket, $project): void {
            ManagedBucket::withTrashed()->whereKey($bucket->id)->lockForUpdate()->firstOrFail();
            /** @var Project $lockedProject */
            $lockedProject = Project::withTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            BucketAttachment::query()
                ->where('bucket_id', $bucket->id)
                ->where('project_id', $lockedProject->id)
                ->firstOrFail()
                ->delete();

            $lockedProject->refreshBucketFingerprint();
            $lockedProject->save();

            BucketAudit::record($actor, BucketAudit::DETACHED, $bucket, [
                'project_id' => $lockedProject->id,
                'reason' => BucketAudit::DETACH_REASON_USER,
            ]);
        });
    }
}
