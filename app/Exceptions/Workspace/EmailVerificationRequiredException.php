<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** Teams are for accounts whose email is verified. */
final class EmailVerificationRequiredException extends WorkspaceRuleException
{
    public function __construct()
    {
        parent::__construct('Verifikasi email kamu dulu sebelum membuat atau bergabung ke workspace tim.');
    }

    public function errorCode(): string
    {
        return 'EMAIL_VERIFICATION_REQUIRED';
    }

    public function status(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
