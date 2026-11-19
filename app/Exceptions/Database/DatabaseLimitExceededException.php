<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

/** The workspace already holds as many managed databases as its limit profile allows. */
final class DatabaseLimitExceededException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit' => 'integer', 'current' => 'integer'];

    public const DESCRIPTION = 'The workspace holds as many databases as its limit allows. `limit` 0 means the workspace may not create databases at all.';

    public function __construct(
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct($limit === 0
            ? 'Workspace ini belum bisa membuat database. Minta admin mengaktifkannya lewat profil batas workspace.'
            : sprintf('Workspace ini sudah punya %d database, dan itu batasnya. Hapus database yang tidak dipakai untuk membuat yang baru.', $current));
    }

    /** @return array{limit: int, current: int} */
    public function context(): array
    {
        return ['limit' => $this->limit, 'current' => $this->current];
    }
}
