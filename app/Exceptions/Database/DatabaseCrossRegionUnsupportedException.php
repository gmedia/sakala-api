<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

/**
 * The project runs, or must run, in another region than the database. Until a
 * private network joins regions, a project only reaches databases of its own.
 */
final class DatabaseCrossRegionUnsupportedException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_CROSS_REGION_UNSUPPORTED';

    public const STATUS = 409;

    public const CONTEXT = ['database_region' => 'string', 'project_region' => 'string'];

    public const DESCRIPTION = 'The project runs, or must run, in another region than the database; attaching across regions waits for a private network between them.';

    public function __construct(
        public readonly string $databaseRegion,
        public readonly string $projectRegion,
    ) {
        parent::__construct(sprintf(
            'Database ini ada di region %s, sedangkan proyek ini berjalan di region %s. Database dan proyek harus di region yang sama, jadi buat database baru di region %s untuk proyek ini.',
            $databaseRegion,
            $projectRegion,
            $projectRegion,
        ));
    }

    /** @return array{database_region: string, project_region: string} */
    public function context(): array
    {
        return ['database_region' => $this->databaseRegion, 'project_region' => $this->projectRegion];
    }
}
