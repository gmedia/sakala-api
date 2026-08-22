<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ResourceLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $resource,
        public readonly int $requested,
        public readonly int $maximum,
        string $message = '',
    ) {
        $msg = $message !== ''
            ? $message
            : sprintf(
                'Requested %s (%d) exceeds the maximum allowed pilot limit (%d).',
                $resource,
                $requested,
                $maximum
            );

        parent::__construct($msg);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'RESOURCE_LIMIT_EXCEEDED',
            'resource' => $this->resource,
            'requested' => $this->requested,
            'maximum' => $this->maximum,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
