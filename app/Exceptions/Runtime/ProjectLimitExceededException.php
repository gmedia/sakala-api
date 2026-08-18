<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ProjectLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current,
        string $message = '',
    ) {
        $msg = $message !== ''
            ? $message
            : sprintf('Project limit reached. You can create up to %d project(s) during the pilot phase.', $limit);

        parent::__construct($msg);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_LIMIT_EXCEEDED',
            'limit' => $this->limit,
            'current' => $this->current,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
