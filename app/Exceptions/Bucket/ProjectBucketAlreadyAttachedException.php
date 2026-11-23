<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

use App\Models\ManagedBucket;

/**
 * A project uses one managed bucket at a time: the variables have fixed
 * names, so a second bucket would have nowhere to go.
 */
final class ProjectBucketAlreadyAttachedException extends BucketRuleException
{
    public const CODE = 'PROJECT_BUCKET_ALREADY_ATTACHED';

    public const STATUS = 409;

    public const CONTEXT = ['bucket' => 'array'];

    public const DESCRIPTION = 'The project already uses another bucket, named in `bucket` (`id`, `name`). A project uses one bucket at a time.';

    public function __construct(
        private readonly ManagedBucket $attached,
    ) {
        parent::__construct(sprintf(
            'Proyek ini sudah memakai bucket %s. Lepas dulu bucket itu dari proyek, lalu pasang yang ini.',
            $attached->name,
        ));
    }

    /** @return array{bucket: array{id: string, name: string}} */
    public function context(): array
    {
        return ['bucket' => ['id' => $this->attached->id, 'name' => $this->attached->name]];
    }
}
