<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

/**
 * The database is locked because its workspace reached its storage quota.
 * Attaching it would hand a project credentials that cannot connect.
 */
final class DatabaseLockedException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_LOCKED';

    public const STATUS = 409;

    public const DESCRIPTION = 'The database is locked because its workspace reached its storage quota; it unlocks once usage is under the quota again.';

    public function __construct()
    {
        parent::__construct('Database ini dikunci karena penyimpanan database workspace sudah penuh, jadi belum bisa dipasang ke proyek. Hapus database yang tidak dipakai, atau minta admin menaikkan kuota.');
    }
}
