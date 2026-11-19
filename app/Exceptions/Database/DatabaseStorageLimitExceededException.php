<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Support\Database\DatabaseSize;

/**
 * The workspace's databases already take all of its storage quota, so its
 * databases are locked; a new one would be locked at once.
 */
final class DatabaseStorageLimitExceededException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_STORAGE_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit_mb' => 'integer', 'current_bytes' => 'integer'];

    public const DESCRIPTION = 'The workspace\'s databases already take all of its storage quota, so a new database would be locked at once.';

    public function __construct(
        public readonly int $limitMb,
        public readonly int $currentBytes,
    ) {
        parent::__construct(sprintf(
            'Penyimpanan database workspace ini sudah penuh (%s dari %d MB), jadi database baru belum bisa dibuat. Hapus database yang tidak dipakai, atau minta admin menaikkan kuota.',
            DatabaseSize::megabytes($currentBytes),
            $limitMb,
        ));
    }

    /** @return array{limit_mb: int, current_bytes: int} */
    public function context(): array
    {
        return ['limit_mb' => $this->limitMb, 'current_bytes' => $this->currentBytes];
    }
}
