<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Data\Bucket\CreateBucketData;
use App\Enums\BucketStatus;
use App\Exceptions\Bucket\BucketCapacityUnavailableException;
use App\Exceptions\Bucket\BucketLimitExceededException;
use App\Exceptions\Bucket\BucketRegionUnavailableException;
use App\Exceptions\Bucket\BucketStorageLimitExceededException;
use App\Exceptions\Bucket\BucketUnavailableException;
use App\Exceptions\Bucket\ProjectBucketAlreadyAttachedException;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Bucket\BucketCommandService;
use App\Services\Bucket\BucketQuotaPolicy;
use App\Services\Bucket\ProjectBucketAttacher;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Bucket\BucketAudit;
use App\Support\Naming\UniqueDisplayName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates a managed bucket in a workspace (ADR-021): random bucket name,
 * access key ID, and secret, a storage node with room for the quota, and the
 * CreateBucket command for it. The bucket is `provisioning` until the node
 * reports.
 *
 * The count and the reserved storage are checked under the workspace row
 * lock, so two requests cannot both take the last slot or the last MB: the
 * sum of a workspace's reservations stays within `max_bucket_storage_mb`.
 * Admins acting are not limited by either, as with projects and databases,
 * but node capacity applies to them too. With a project, the bucket is
 * attached to it in the same transaction, the only way a bucket that is
 * still being created gets attached. Locks follow the workspace, then the
 * project, as databases and deployments take them.
 */
final class CreateBucketAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly BucketCommandService $commands,
        private readonly BucketQuotaPolicy $quotas,
        private readonly ProjectBucketAttacher $attacher,
    ) {}

    /**
     * @throws BucketUnavailableException
     * @throws ProjectBucketAlreadyAttachedException
     * @throws BucketLimitExceededException
     * @throws BucketStorageLimitExceededException
     * @throws BucketRegionUnavailableException
     * @throws BucketCapacityUnavailableException
     */
    public function handle(User $actor, Workspace $workspace, CreateBucketData $data): ManagedBucket
    {
        $project = $data->project;

        if ($project !== null && $project->workspace_id !== $workspace->id) {
            throw new InvalidArgumentException('A bucket is created only for projects of its own workspace.');
        }

        if ($project !== null && ! $this->scheduler->bucketsAvailable()) {
            throw new BucketUnavailableException;
        }

        return DB::transaction(function () use ($actor, $workspace, $data, $project): ManagedBucket {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            /** @var Project|null $lockedProject */
            $lockedProject = $project === null ? null : Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            if ($lockedProject !== null) {
                $this->attacher->ensureNoneAttached($this->attacher->attachmentOf($lockedProject));
            }

            $quotaMb = $actor->isAdmin()
                ? $data->quotaMb ?? $this->quotas->defaultQuotaMb()
                : $this->quotas->ensureCreateAllowed($locked, $data->quotaMb);

            $name = $this->nameFor($locked, $data->name, $lockedProject);
            $region = $data->region
                ?? ($lockedProject === null ? null : $this->scheduler->regionOf($lockedProject))
                ?? $this->limits->defaultRegionOf($locked);

            $node = $this->scheduler->selectBucketNode($region, $quotaMb);
            $secret = Str::random(ManagedBucket::SECRET_LENGTH);

            $bucket = ManagedBucket::query()->create([
                'workspace_id' => $locked->id,
                'name' => $name,
                'region' => $node->effectiveRegion(),
                'status' => BucketStatus::Provisioning,
                'node_id' => $node->id,
                's3_bucket' => $this->newBucketName(),
                'access_key_id' => $this->newAccessKeyId(),
                'secret_access_key' => $secret,
                'quota_mb' => $quotaMb,
                'reserved_quota_mb' => $quotaMb,
                'created_by' => $actor->id,
            ]);

            $command = $this->commands->create($bucket, $secret);

            BucketAudit::record($actor, BucketAudit::CREATED, $bucket, [
                'command_id' => $command->id,
                'node_id' => $node->id,
                'region' => $bucket->region,
                's3_bucket' => $bucket->s3_bucket,
                'access_key_id' => $bucket->access_key_id,
                'quota_mb' => $quotaMb,
                ...($lockedProject === null ? [] : ['project_id' => $lockedProject->id]),
            ]);

            if ($lockedProject !== null) {
                $this->attacher->attach($actor, $bucket, $lockedProject);
            }

            return $bucket;
        });
    }

    /**
     * The requested name, still free under the lock (the request checked
     * too), or without one the project's name made unique among the
     * workspace's live buckets.
     */
    private function nameFor(Workspace $workspace, ?string $requested, ?Project $project): string
    {
        /** @var list<string> $taken */
        $taken = ManagedBucket::query()->where('workspace_id', $workspace->id)->pluck('name')->all();

        if ($requested !== null) {
            if (in_array($requested, $taken, true)) {
                throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
            }

            return $requested;
        }

        if ($project === null) {
            throw new InvalidArgumentException('A bucket without a project needs a name.');
        }

        return UniqueDisplayName::from($project->name, $taken, ManagedBucket::MAX_NAME_LENGTH);
    }

    /**
     * `b-` and random lowercase letters and digits, never derived from what
     * people type, so a presigned URL reveals no workspace or project name,
     * and never reused, so an old key or URL can never reach a newer bucket.
     */
    private function newBucketName(): string
    {
        do {
            $name = ManagedBucket::S3_NAME_PREFIX.Str::lower(Str::random(ManagedBucket::S3_NAME_RANDOM_LENGTH));
        } while (ManagedBucket::withTrashed()->where('s3_bucket', $name)->exists());

        return $name;
    }

    /** `SK` and random uppercase letters and digits, also the RustFS user name; never reused. */
    private function newAccessKeyId(): string
    {
        do {
            $id = ManagedBucket::ACCESS_KEY_PREFIX.Str::upper(Str::random(ManagedBucket::ACCESS_KEY_RANDOM_LENGTH));
        } while (ManagedBucket::withTrashed()->where('access_key_id', $id)->exists());

        return $id;
    }
}
