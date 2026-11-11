<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use Symfony\Component\HttpFoundation\Response;

/** Sakala always keeps at least one admin. */
final class LastAdminException extends AdminRuleException
{
    public function __construct()
    {
        parent::__construct('Akun ini admin terakhir. Jadikan akun lain admin dulu, lalu cabut peran admin akun ini.');
    }

    public function errorCode(): string
    {
        return 'LAST_ADMIN';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
