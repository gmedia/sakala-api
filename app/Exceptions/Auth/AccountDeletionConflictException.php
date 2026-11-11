<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Projects kept appearing while the account was being deleted. Safe to retry. */
final class AccountDeletionConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Akun kamu belum terhapus karena ada proyek baru yang sedang dibuat. Tutup tab lain, lalu coba lagi.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ACCOUNT_DELETION_CONFLICT',
        ], Response::HTTP_CONFLICT);
    }
}
