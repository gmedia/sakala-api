<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class LegalAcceptanceRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Setujui dulu Syarat Layanan dan Kebijakan Penggunaan Wajar versi terbaru untuk membuat proyek atau men-deploy.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'LEGAL_ACCEPTANCE_REQUIRED',
        ], Response::HTTP_FORBIDDEN);
    }
}
