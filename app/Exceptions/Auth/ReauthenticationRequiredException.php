<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** An account without a password proves itself by logging in at its provider again. */
final class ReauthenticationRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Demi keamanan, masuk ulang lewat GitHub atau Google dulu, lalu ulangi langkah ini.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'REAUTHENTICATION_REQUIRED',
        ], Response::HTTP_FORBIDDEN);
    }
}
