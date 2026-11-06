<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown before the request body is read, so the answer is the same for a
 * registered email, an unknown one, and an invalid one.
 */
final class EmailDeliveryUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sakala belum bisa mengirim email untuk saat ini, jadi pendaftaran, reset kata sandi, verifikasi, dan ganti email lewat email belum tersedia. Kalau belum punya akun, masuk lewat GitHub atau hubungi admin Sakala.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'EMAIL_DELIVERY_UNAVAILABLE',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
