<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deploying, or saving a worker definition, would run more worker containers
 * than the workspace's `max_worker_containers` allows (ADR-022). Worker
 * containers of sleeping projects do not count, like containers.
 */
final class WorkerContainerLimitExceededException extends RuntimeException
{
    /** The limit name usage signals record for this rejection. */
    public const LIMIT_NAME = 'max_worker_containers';

    public const CODE = 'WORKER_CONTAINER_LIMIT_EXCEEDED';

    public function __construct(
        public readonly int $limit,
        /** Worker containers other projects of the workspace run now. */
        public readonly int $current,
        /** Worker containers this project would run. */
        public readonly int $requested,
    ) {
        parent::__construct($limit === 0
            ? 'Workspace ini belum boleh menjalankan worker. Hapus worker di pengaturan proyek atau minta admin Sakala membuka batasnya, lalu deploy lagi.'
            : sprintf(
                'Proyek ini akan menjalankan %d worker, tetapi workspace ini sudah menjalankan %d dari batas %d worker. Kurangi replika worker, atau tunggu sampai proyek lain yang punya worker tidur, lalu deploy lagi.',
                $requested,
                $current,
                $limit,
            ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'limit' => $this->limit,
            'current' => $this->current,
            'requested' => $this->requested,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
