<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Bucket;

use App\Models\ManagedBucket;

final class UpdateBucketRequest extends StoreBucketRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('bucket')) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /** New name people see, unique among the workspace's live buckets. Never changes anything on the node. */
            'name' => ['required_without:quota_mb', 'string', 'max:'.ManagedBucket::MAX_NAME_LENGTH, $this->uniqueName()],
            /**
             * New hard quota in MB. Needs a `ready` or `locked` bucket and no quota change on its way. An increase must
             * fit the workspace's bucket storage and the storage node; a decrease below current usage is allowed, and
             * uploads are refused until usage is under it.
             */
            'quota_mb' => ['required_without:name', 'integer', 'between:'.ManagedBucket::MIN_QUOTA_MB.','.ManagedBucket::MAX_QUOTA_MB],
        ];
    }

    public function newName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? trim($name) : null;
    }

    public function newQuotaMb(): ?int
    {
        $quota = $this->validated('quota_mb');

        return $quota === null ? null : (int) $quota;
    }
}
