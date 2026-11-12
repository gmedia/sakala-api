<?php

declare(strict_types=1);

namespace App\Exceptions\GitHub;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * GitHub answered with an error, an unreadable body, or not at all. The user's
 * request was fine, so this is a 503 with a stable code instead of a 500.
 */
final class GithubUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Sakala belum bisa mengambil data dari GitHub. Coba lagi beberapa saat lagi.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'GITHUB_UNAVAILABLE',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
