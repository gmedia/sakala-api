<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown only after the password matched. Wrong passwords and unknown emails
 * keep the generic 401, so this response cannot reveal which emails exist.
 */
final class EmailNotVerifiedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(config('sakala.email_delivery_enabled')
            ? 'Email kamu belum diverifikasi. Buka tautan verifikasi yang kami kirim ke email kamu, atau minta kirim ulang tautannya.'
            : 'Email kamu belum diverifikasi, dan Sakala belum bisa mengirim email verifikasi untuk saat ini. Coba masuk lewat GitHub, atau hubungi admin Sakala.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'email_not_verified',
        ], Response::HTTP_FORBIDDEN);
    }
}
