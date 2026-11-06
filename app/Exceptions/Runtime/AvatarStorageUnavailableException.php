<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class AvatarStorageUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Foto profil belum bisa disimpan karena penyimpanan Sakala sedang tidak tersedia. Coba lagi nanti, atau hubungi admin Sakala.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'AVATAR_STORAGE_UNAVAILABLE',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
