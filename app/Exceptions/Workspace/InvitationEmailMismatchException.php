<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** An owner invitation is accepted only by the account with the invited verified email. */
final class InvitationEmailMismatchException extends WorkspaceRuleException
{
    public function __construct()
    {
        parent::__construct('Undangan sebagai pemilik hanya bisa diterima oleh akun dengan email yang diundang. Masuk dengan akun itu, atau minta undangan baru ke email akunmu.');
    }

    public function errorCode(): string
    {
        return 'INVITATION_EMAIL_MISMATCH';
    }

    public function status(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
