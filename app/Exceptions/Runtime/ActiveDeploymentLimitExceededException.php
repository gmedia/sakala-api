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
                'Active deployment limit reached. You can only have up to %d active deployment(s) %s during the pilot phase.',
                $limit,
                $scope === 'project' ? 'for this project' : 'simultaneously'
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
