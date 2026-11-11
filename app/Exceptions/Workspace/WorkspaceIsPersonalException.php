<?php

declare(strict_types=1);

namespace App\Exceptions\Workspace;

use Symfony\Component\HttpFoundation\Response;

/** Rename, delete, leave, invite, and member changes are for teams only. */
final class WorkspaceIsPersonalException extends WorkspaceRuleException
{
    public function __construct()
    {
        parent::__construct('Workspace pribadi tidak bisa diganti namanya, dihapus, ditinggalkan, atau diberi anggota. Buat workspace tim untuk bekerja bersama.');
    }

    public function errorCode(): string
    {
        return 'WORKSPACE_IS_PERSONAL';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
