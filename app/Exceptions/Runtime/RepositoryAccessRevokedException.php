<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class RepositoryAccessRevokedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pemilik project tidak lagi punya akses ke repository ini di GitHub, jadi project ini belum bisa di-deploy. Minta akses dipulihkan, atau masuk ulang dengan GitHub bila akses sudah benar.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'REPOSITORY_ACCESS_REVOKED',
        ], Response::HTTP_CONFLICT);
    }
}
