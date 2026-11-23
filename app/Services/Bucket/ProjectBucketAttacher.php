<?php

declare(strict_types=1);

namespace App\Services\Bucket;

use App\Exceptions\Bucket\ProjectBucketAlreadyAttachedException;
use App\Models\BucketAttachment;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Support\Bucket\BucketAudit;

/**
 * The rules and writes shared by every path that attaches a bucket to a
 * project: attaching an existing bucket and creating one for a project.
 * Callers hold the project row lock, and the bucket row lock or a bucket
 * they just created, inside their transaction. There is no region rule: the
 * S3 endpoint is public, so a project reaches a bucket of any region.
 */
final class ProjectBucketAttacher
{
    /** The project's attachment, at most one (unique `bucket_attachments.project_id`). */
    public function attachmentOf(Project $lockedProject): ?BucketAttachment
    {
        return BucketAttachment::query()->where('project_id', $lockedProject->id)->first();
    }

    /**
     * One bucket per project: its variables have fixed names.
     *
     * @throws ProjectBucketAlreadyAttachedException
     */
    public function ensureNoneAttached(?BucketAttachment $existing): void
    {
        if ($existing !== null) {
            throw new ProjectBucketAlreadyAttachedException($existing->bucket()->withTrashed()->firstOrFail());
        }
    }

    /**
     * Attach, so the project's next deployment receives the variables, and
     * record it.
     */
    public function attach(User $actor, ManagedBucket $bucket, Project $lockedProject): BucketAttachment
    {
        $attachment = BucketAttachment::query()->create([
            'bucket_id' => $bucket->id,
            'project_id' => $lockedProject->id,
            'created_by' => $actor->id,
        ]);

        $lockedProject->refreshBucketFingerprint();
        $lockedProject->save();

        BucketAudit::record($actor, BucketAudit::ATTACHED, $bucket, ['project_id' => $lockedProject->id]);

        return $attachment;
    }
}
