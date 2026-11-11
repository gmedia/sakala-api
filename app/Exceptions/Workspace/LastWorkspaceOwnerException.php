<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use App\Models\Workspace;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * A team always keeps an owner. Thrown when the last owner leaves, is removed
 * or demoted, or deletes their account while the team still has projects.
 */
final class LastWorkspaceOwnerException extends WorkspaceRuleException
{
    /** @param Collection<int, Workspace> $workspaces */
    public function __construct(
        private readonly Collection $workspaces,
        bool $accountDeletion = false,
    ) {
        parent::__construct($accountDeletion
            ? 'Kamu satu-satunya pemilik workspace tim yang masih punya proyek. Jadikan anggota lain pemilik, atau hapus proyeknya, sebelum menghapus akun.'
            : 'Workspace tim harus punya setidaknya satu pemilik. Jadikan anggota lain pemilik dulu.');
    }

    public function errorCode(): string
    {
        return 'LAST_WORKSPACE_OWNER';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /** @return array{workspaces: list<array{id: string, name: string, slug: string}>} */
    public function context(): array
    {
        return [
            'workspaces' => array_values($this->workspaces
                ->map(fn (Workspace $workspace): array => ['id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug])
                ->all()),
        ];
    }
}
