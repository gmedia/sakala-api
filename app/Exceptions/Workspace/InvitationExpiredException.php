<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** The invitation is past its expiry; an owner can invite again. */
final class InvitationExpiredException extends WorkspaceRuleException
{
    public function __construct()
    {
        parent::__construct('Undangan ini sudah kedaluwarsa. Minta pemilik workspace mengirim undangan baru.');
    }

    public function errorCode(): string
    {
        return 'INVITATION_EXPIRED';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
