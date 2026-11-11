<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class OriginNotAllowedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Permintaan ini hanya diterima dari Console Sakala.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ORIGIN_NOT_ALLOWED',
        ], Response::HTTP_FORBIDDEN);
    }
}
