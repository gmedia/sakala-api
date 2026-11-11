<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use Symfony\Component\HttpFoundation\Response;

/** A limit profile is deleted only once no workspace runs under it. */
final class LimitProfileInUseException extends AdminRuleException
{
    public function __construct(private readonly int $workspaces)
    {
        parent::__construct('Profil batas ini masih dipakai workspace. Lepas atau ganti profilnya di workspace tersebut dulu, lalu hapus.');
    }

    public function errorCode(): string
    {
        return 'LIMIT_PROFILE_IN_USE';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /** @return array{workspaces: int} */
    public function context(): array
    {
        return ['workspaces' => $this->workspaces];
    }
}
