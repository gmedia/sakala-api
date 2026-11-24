<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project has worker processes, but no node that could run the
 * deployment advertises the `worker-processes` capability (ADR-022). An older
 * agent would ignore the field and start the web process alone, so the
 * deployment is refused instead of reporting success without its workers.
 */
final class ProjectWorkerProcessesUnsupportedException extends RuntimeException
{
    public const CODE = 'PROJECT_WORKER_PROCESSES_UNSUPPORTED';

    public function __construct()
    {
        parent::__construct('Proyek ini punya worker, tetapi node yang menjalankannya belum mendukung worker. Hapus worker di pengaturan proyek untuk deploy sekarang, atau tunggu operator memperbarui agent di node, lalu deploy lagi.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
        ], Response::HTTP_CONFLICT);
    }
}
