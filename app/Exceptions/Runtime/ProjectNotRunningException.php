<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a project whose live deployment runs or crashed can be restarted. A
 * sleeping one wakes on the next visit, a stopped or never deployed one needs
 * a deployment.
 */
final class ProjectNotRunningException extends RuntimeException
{
    public const CODE = 'PROJECT_NOT_RUNNING';

    public function __construct(
        public readonly string $runtimeStatus,
    ) {
        parent::__construct('Proyek ini sedang tidak berjalan, jadi tidak ada container yang bisa dinyalakan ulang. Deploy proyek kalau ingin menjalankannya lagi.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'runtime_status' => $this->runtimeStatus,
        ], Response::HTTP_CONFLICT);
    }
}
