<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Waking a sleeping project would run more containers than its workspace may.
 * The public wake endpoint leaves out the counts, since anyone can call it.
 */
final class ProjectWakeLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current,
        public readonly bool $exposeUsage = true,
    ) {
        parent::__construct(
            'Proyek ini belum bisa dibangunkan karena workspace-nya sudah menjalankan proyek sebanyak batasnya. Hentikan atau hapus proyek lain dulu, lalu coba lagi.',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_WAKE_LIMIT_EXCEEDED',
            ...($this->exposeUsage ? ['limit' => $this->limit, 'current' => $this->current] : []),
        ], Response::HTTP_CONFLICT);
    }
}
