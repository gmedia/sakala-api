<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** A team is deleted only once its projects and managed resources are gone. */
final class WorkspaceNotEmptyException extends WorkspaceRuleException
{
    public function __construct(
        private readonly int $projects,
        private readonly int $databases = 0,
        private readonly int $buckets = 0,
        private readonly int $valkeys = 0,
    ) {
        $left = array_keys(array_filter(['proyek' => $projects > 0, 'database' => $databases > 0, 'bucket' => $buckets > 0, 'Valkey' => $valkeys > 0]));

        parent::__construct(match (true) {
            ($buckets > 0 || $valkeys > 0) && $left !== [] => sprintf(
                'Workspace ini masih punya %s. Hapus semuanya dulu, lalu hapus workspace-nya.',
                self::joined($left),
            ),
            $projects > 0 && $databases > 0 => 'Workspace ini masih punya proyek dan database. Hapus keduanya dulu, lalu hapus workspace-nya.',
            $databases > 0 => 'Workspace ini masih punya database. Hapus database-nya dulu, lalu hapus workspace-nya.',
            default => 'Workspace ini masih punya proyek. Hapus proyeknya dulu, lalu hapus workspace-nya.',
        });
    }

    /** @param  non-empty-list<string>  $items */
    private static function joined(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? $last : implode(', ', $items).' dan '.$last;
    }

    public function errorCode(): string
    {
        return 'WORKSPACE_NOT_EMPTY';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /** @return array{projects: int, databases: int, buckets: int, valkeys: int} */
    public function context(): array
    {
        return ['projects' => $this->projects, 'databases' => $this->databases, 'buckets' => $this->buckets, 'valkeys' => $this->valkeys];
    }
}
