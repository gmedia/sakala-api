<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Models\ManagedDatabase;

/**
 * A deployment needs the connection variables of its attached database, and
 * the database node has not reported where projects reach it
 * (`metadata.database.endpoint`). Deploying without them would start an
 * application that cannot reach its database.
 */
final class DatabaseEndpointUnavailableException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_ENDPOINT_UNAVAILABLE';

    public const STATUS = 409;

    public const CONTEXT = ['database' => 'array'];

    public const DESCRIPTION = 'The node of the attached database (`database`: `id`, `name`) has not reported its address yet, so the connection variables cannot be built.';

    public function __construct(
        public readonly ManagedDatabase $database,
    ) {
        parent::__construct(sprintf(
            'Server database untuk %s belum melaporkan alamatnya, jadi proyek ini belum bisa di-deploy dengan database terpasang. Coba lagi beberapa saat lagi, atau lepas database-nya dulu.',
            $database->name,
        ));
    }

    /** @return array{database: array{id: string, name: string}} */
    public function context(): array
    {
        return ['database' => ['id' => $this->database->id, 'name' => $this->database->name]];
    }
}
