<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** Owning one more team would pass the user's cap; an admin can raise it. */
final class TeamLimitExceededException extends WorkspaceRuleException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current,
        bool $actingForSelf = true,
    ) {
        parent::__construct(sprintf($actingForSelf
            ? 'Kamu sudah memiliki %d workspace tim, dan itu batasnya. Hapus tim yang tidak dipakai atau minta admin Sakala menaikkan batasnya.'
            : 'Orang ini sudah memiliki %d workspace tim, dan itu batasnya, jadi belum bisa dijadikan pemilik. Admin Sakala bisa menaikkan batasnya.', $limit));
    }

    public function errorCode(): string
    {
        return 'TEAM_LIMIT_EXCEEDED';
    }

    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    /** @return array{limit: int, current: int} */
    public function context(): array
    {
        return ['limit' => $this->limit, 'current' => $this->current];
    }
}
