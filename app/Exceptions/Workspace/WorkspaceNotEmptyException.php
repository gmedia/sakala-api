<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** A team is deleted only once its projects and managed databases are gone. */
final class WorkspaceNotEmptyException extends WorkspaceRuleException
{
    public function __construct(
        private readonly int $projects,
        private readonly int $databases = 0,
    ) {
        parent::__construct(match (true) {
            $projects > 0 && $databases > 0 => 'Workspace ini masih punya proyek dan database. Hapus keduanya dulu, lalu hapus workspace-nya.',
            $databases > 0 => 'Workspace ini masih punya database. Hapus database-nya dulu, lalu hapus workspace-nya.',
            default => 'Workspace ini masih punya proyek. Hapus proyeknya dulu, lalu hapus workspace-nya.',
        });
    }

    public function errorCode(): string
    {
        return 'WORKSPACE_NOT_EMPTY';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /** @return array{projects: int, databases: int} */
    public function context(): array
    {
        return ['projects' => $this->projects, 'databases' => $this->databases];
    }
}
