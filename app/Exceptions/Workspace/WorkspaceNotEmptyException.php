<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** A team is deleted only once its projects are gone. */
final class WorkspaceNotEmptyException extends WorkspaceRuleException
{
    public function __construct(private readonly int $projects)
    {
        parent::__construct('Workspace ini masih punya proyek. Hapus proyeknya dulu, lalu hapus workspace-nya.');
    }

    public function errorCode(): string
    {
        return 'WORKSPACE_NOT_EMPTY';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /** @return array{projects: int} */
    public function context(): array
    {
        return ['projects' => $this->projects];
    }
}
