<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** Inviting or accepting for someone who is already a member. */
final class AlreadyWorkspaceMemberException extends WorkspaceRuleException
{
    public function __construct()
    {
        parent::__construct('Orang ini sudah menjadi anggota workspace.');
    }

    public function errorCode(): string
    {
        return 'ALREADY_WORKSPACE_MEMBER';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
