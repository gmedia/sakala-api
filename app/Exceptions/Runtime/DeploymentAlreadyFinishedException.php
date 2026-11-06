<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use App\Enums\DeploymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class DeploymentAlreadyFinishedException extends RuntimeException
{
    public function __construct(
        public readonly DeploymentStatus $status,
    ) {
        parent::__construct($status === DeploymentStatus::Succeeded
            ? 'Deployment ini sudah berhasil, jadi tidak bisa dibatalkan lagi.'
            : 'Deployment ini sudah gagal, jadi tidak ada yang perlu dibatalkan.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'DEPLOYMENT_ALREADY_FINISHED',
            'status' => $this->status->value,
        ], Response::HTTP_CONFLICT);
    }
}
