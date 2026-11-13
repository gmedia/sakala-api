<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deploying a project that is not serving yet would run more containers than
 * the workspace's limit allows. Redeploying a serving project never hits this.
 */
final class ActiveContainerLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct(sprintf(
            'Workspace ini sudah menjalankan %d proyek, dan itu batasnya. Hapus proyek yang tidak dipakai atau tunggu sampai ada proyek yang tidur, lalu deploy lagi.',
            $limit,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ACTIVE_CONTAINER_LIMIT_EXCEEDED',
            'limit' => $this->limit,
            'current' => $this->current,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
