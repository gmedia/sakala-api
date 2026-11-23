<?php

declare(strict_types=1);

namespace App\Data\Bucket;

use App\Models\Project;

/**
 * What creating a managed bucket asks for. With a project, the bucket is
 * attached to it in the same transaction, and `name` and `region` may be
 * left to the project.
 */
final readonly class CreateBucketData
{
    public function __construct(
        /** Null only with a project: the project's name, made unique in the workspace. */
        public ?string $name,
        /** Null for the project's region, then the workspace's default region, then any region. */
        public ?string $region,
        /** Null for the configured default, capped at what the workspace has left. */
        public ?int $quotaMb = null,
        /** Already checked to be a project of the workspace the caller may update. */
        public ?Project $project = null,
    ) {}
}
