<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deploying would run more containers than the workspace's limit allows: a
 * project that is not serving yet, or a serving one whose next deployment runs
 * more replicas or worker containers than it does now. Redeploying with the
 * same or fewer containers never hits this.
 */
final class ActiveContainerLimitExceededException extends RuntimeException
{
    /** The limit name usage signals record for this rejection. */
    public const LIMIT_NAME = 'max_active_containers';

    public function __construct(
        public readonly int $limit,
        public readonly int $current,
        /** Containers the deployment runs: the project's replicas and worker containers. */
        public readonly int $requested = Project::DEFAULT_REPLICAS,
        /** Of those, the worker containers (ADR-022). */
        public readonly int $workers = 0,
    ) {
        parent::__construct(match (true) {
            $workers > 0 => sprintf(
                'Deployment ini menjalankan %d container (%d replika web dan %d worker), tetapi workspace ini sudah menjalankan %d dari batas %d container. Kurangi replika atau worker, hapus proyek yang tidak dipakai, atau tunggu sampai ada proyek yang tidur, lalu deploy lagi.',
                $requested,
                $requested - $workers,
                $workers,
                $current,
                $limit,
            ),
            $requested > Project::DEFAULT_REPLICAS => sprintf(
                'Deployment ini menjalankan %d replika, tetapi workspace ini sudah menjalankan %d dari batas %d container. Kurangi jumlah replika, hapus proyek yang tidak dipakai, atau tunggu sampai ada proyek yang tidur, lalu deploy lagi.',
                $requested,
                $current,
                $limit,
            ),
            default => sprintf(
                'Workspace ini sudah menjalankan %d proyek, dan itu batasnya. Hapus proyek yang tidak dipakai atau tunggu sampai ada proyek yang tidur, lalu deploy lagi.',
                $limit,
            ),
        });
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'ACTIVE_CONTAINER_LIMIT_EXCEEDED',
            'limit' => $this->limit,
            'current' => $this->current,
            'requested' => $this->requested,
            'workers' => $this->workers,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
