<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Models\ManagedDatabase;

/**
 * A project uses one managed database at a time: the connection variables have
 * fixed names, so a second database would have nowhere to go.
 */
final class ProjectDatabaseAlreadyAttachedException extends DatabaseRuleException
{
    public const CODE = 'PROJECT_DATABASE_ALREADY_ATTACHED';

    public const STATUS = 409;

    public const CONTEXT = ['database' => 'array'];

    public const DESCRIPTION = 'The project already uses another database, named in `database` (`id`, `name`). A project uses one database at a time.';

    public function __construct(
        private readonly ManagedDatabase $attached,
    ) {
        parent::__construct(sprintf(
            'Proyek ini sudah memakai database %s. Lepas dulu database itu dari proyek, lalu pasang yang ini.',
            $attached->name,
        ));
    }

    /** @return array{database: array{id: string, name: string}} */
    public function context(): array
    {
        return ['database' => ['id' => $this->attached->id, 'name' => $this->attached->name]];
    }
}
