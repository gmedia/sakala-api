<?php

declare(strict_types=1);

namespace App\Exceptions\CustomDomain;

/** The workspace holds as many custom domains as its limit profile allows. */
final class CustomDomainLimitExceededException extends CustomDomainRuleException
{
    public const CODE = 'CUSTOM_DOMAIN_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit' => 'integer', 'current' => 'integer'];

    public const DESCRIPTION = 'The workspace holds as many custom domains as its limit allows (`limit`, `current`); pending, failed, and disabled ones count. `limit` 0 means the workspace may not add custom domains.';

    public function __construct(
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct($limit === 0
            ? 'Workspace ini belum bisa memakai custom domain. Minta admin Sakala membukanya lewat profil batas workspace.'
            : sprintf('Workspace ini sudah punya %d custom domain, dan itu batasnya. Domain yang menunggu, gagal, atau nonaktif ikut dihitung. Hapus yang tidak dipakai untuk menambah yang baru.', $current));
    }

    /** @return array{limit: int, current: int} */
    public function context(): array
    {
        return ['limit' => $this->limit, 'current' => $this->current];
    }
}
