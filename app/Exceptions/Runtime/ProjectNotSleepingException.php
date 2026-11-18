<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a project that sleeps can be woken. Anything else (running, stopped,
 * deploying, crashed) has its own way back, which a wake would bypass.
 */
final class ProjectNotSleepingException extends RuntimeException
{
    public function __construct(
        public readonly string $runtimeStatus,
    ) {
        parent::__construct('Proyek ini sedang tidak tidur, jadi tidak perlu dibangunkan.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_NOT_SLEEPING',
            'runtime_status' => $this->runtimeStatus,
        ], Response::HTTP_CONFLICT);
    }
}
