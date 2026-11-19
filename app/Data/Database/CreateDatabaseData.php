<?php

declare(strict_types=1);

namespace App\Data\Database;

use App\Models\Project;

/**
 * What creating a managed database asks for. With a project, the database is
 * attached to it in the same transaction, and `name` and `region` may be left
 * to the project.
 */
final readonly class CreateDatabaseData
{
    public function __construct(
        /** Null only with a project: the project's name, made unique in the workspace. */
        public ?string $name,
        /** Null for the project's region, then the workspace's default region. */
        public ?string $region,
        /** Already checked to be a project of the workspace the caller may update. */
        public ?Project $project = null,
        /** Deploy the project once the database is ready, as the caller. Only with a project. */
        public bool $deployWhenReady = false,
    ) {}
}
