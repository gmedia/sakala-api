<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * One answer for a wrong token, an expired token, and an unknown email, so
 * this endpoint cannot be used to learn which emails are registered.
 */
final class InvalidPasswordResetTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tautan reset kata sandi ini sudah tidak berlaku. Minta tautan baru dari halaman lupa kata sandi.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'invalid_reset_token',
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
