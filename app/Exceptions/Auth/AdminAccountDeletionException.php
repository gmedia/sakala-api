<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** A sole admin deleting the account would leave nobody able to handle abuse. */
final class AdminAccountDeletionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Akun admin tidak bisa dihapus sendiri. Minta maintainer lain mencabut peran admin kamu dulu.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ADMIN_ACCOUNT_CANNOT_BE_DELETED',
        ], Response::HTTP_CONFLICT);
    }
}
