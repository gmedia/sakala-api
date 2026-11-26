<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

/** The workspace already holds as many Valkey stores as its limit profile allows. */
final class ValkeyLimitExceededException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit' => 'integer', 'current' => 'integer'];

    public const DESCRIPTION = 'The workspace holds as many Valkey stores as its limit allows (`limit`, `current`). `limit` 0 means the workspace may not create stores yet.';

    public function __construct(
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct($limit === 0
            ? 'Workspace ini belum bisa membuat Valkey. Minta admin mengaktifkannya lewat profil batas workspace.'
            : sprintf('Workspace ini sudah punya %d Valkey, dan itu batasnya. Hapus yang tidak dipakai untuk membuat yang baru.', $current));
    }

    /** @return array{limit: int, current: int} */
    public function context(): array
    {
        return ['limit' => $this->limit, 'current' => $this->current];
    }
}
