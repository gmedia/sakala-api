<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use Symfony\Component\HttpFoundation\Response;

/** An admin cannot remove their own admin role; another admin has to. */
final class AdminSelfDemotionException extends AdminRuleException
{
    public function __construct()
    {
        parent::__construct('Kamu tidak bisa mencabut peran admin dari akunmu sendiri. Minta admin lain melakukannya.');
    }

    public function errorCode(): string
    {
        return 'ADMIN_SELF_DEMOTION';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
