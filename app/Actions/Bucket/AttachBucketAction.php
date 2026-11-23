<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\BucketStatus;
use App\Exceptions\Bucket\BucketLockedException;
use App\Exceptions\Bucket\BucketNotReadyException;
use App\Exceptions\Bucket\ProjectBucketAlreadyAttachedException;
use App\Models\BucketAttachment;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Services\Bucket\ProjectBucketAttacher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Attaches a bucket to a project of the same workspace. The project's next
 * deployment receives the variables, so the project's bucket fingerprint
 * changes and `redeploy_required` turns true. Attaching again is a no-op.
 * The bucket row is locked before the project row, the order every bucket
 * path takes. Only a ready bucket attaches here; a bucket still being created
 * is attached only by CreateBucketAction, for its project.
 */
final class AttachBucketAction
{
    public function __construct(
        private readonly ProjectBucketAttacher $attacher,
    ) {}

    /**
     * @return array{attachment: BucketAttachment, created: bool}
     *
     * @throws BucketLockedException
     * @throws BucketNotReadyException
     * @throws ProjectBucketAlreadyAttachedException
     */
    public function handle(User $actor, ManagedBucket $bucket, Project $project): array
    {
        if ($project->workspace_id === null || $project->workspace_id !== $bucket->workspace_id) {
            throw new InvalidArgumentException('A bucket attaches only to projects of its own workspace.');
        }

        return DB::transaction(function () use ($actor, $bucket, $project): array {
            /** @var ManagedBucket $lockedBucket */
            $lockedBucket = ManagedBucket::query()->whereKey($bucket->id)->lockForUpdate()->firstOrFail();
            /** @var Project $lockedProject */
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $existing = $this->attacher->attachmentOf($lockedProject);

            if ($existing?->bucket_id === $lockedBucket->id) {
                return ['attachment' => $existing, 'created' => false];
            }

            if ($lockedBucket->status === BucketStatus::Locked) {
                throw new BucketLockedException;
            }

            if (! $lockedBucket->status->isProvisioned()) {
                throw new BucketNotReadyException($lockedBucket->status);
            }

            $this->attacher->ensureNoneAttached($existing);

            return ['attachment' => $this->attacher->attach($actor, $lockedBucket, $lockedProject), 'created' => true];
        });
    }
}
