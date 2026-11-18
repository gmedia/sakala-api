<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use App\Models\Project;
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
        /** Containers the wake starts: every replica of the sleeping deployment. */
        public readonly int $requested = Project::DEFAULT_REPLICAS,
    ) {
        parent::__construct($requested > Project::DEFAULT_REPLICAS
            ? sprintf(
                'Proyek ini belum bisa dibangunkan karena %d replikanya akan melewati batas container yang berjalan di workspace-nya. Hapus proyek yang tidak dipakai atau tunggu sampai ada proyek yang tidur, lalu coba lagi.',
                $requested,
            )
            : 'Proyek ini belum bisa dibangunkan karena workspace-nya sudah menjalankan proyek sebanyak batasnya. Hapus proyek yang tidak dipakai atau tunggu sampai ada proyek yang tidur, lalu coba lagi.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_WAKE_LIMIT_EXCEEDED',
            ...($this->exposeUsage ? ['limit' => $this->limit, 'current' => $this->current, 'requested' => $this->requested] : []),
        ], Response::HTTP_CONFLICT);
    }
}
