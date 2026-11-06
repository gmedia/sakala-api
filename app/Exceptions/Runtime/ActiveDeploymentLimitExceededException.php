<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ActiveDeploymentLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current,
        public readonly string $scope = 'user',
        string $message = '',
    ) {
        $msg = $message !== ''
            ? $message
            : sprintf(
                $scope === 'project'
                    ? 'Project ini sudah punya %d deployment yang berjalan, dan itu batas selama fase pilot. Tunggu sampai selesai, lalu deploy lagi.'
                    : 'Kamu sudah punya %d deployment yang berjalan, dan itu batas selama fase pilot. Tunggu salah satunya selesai, lalu deploy lagi.',
                $limit,
            );

        parent::__construct($msg);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED',
            'scope' => $this->scope,
            'limit' => $this->limit,
            'current' => $this->current,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
