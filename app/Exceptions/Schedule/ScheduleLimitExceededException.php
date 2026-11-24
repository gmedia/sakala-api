<?php

declare(strict_types=1);

namespace App\Exceptions\Schedule;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creating or enabling a schedule would give the workspace more enabled
 * schedules than its `max_schedules` (ADR-022). A disabled schedule does not
 * count, so it can always be saved.
 */
final class ScheduleLimitExceededException extends RuntimeException
{
    public const CODE = 'SCHEDULE_LIMIT_EXCEEDED';

    public function __construct(
        public readonly int $limit,
        public readonly int $current,
    ) {
        parent::__construct($limit === 0
            ? 'Workspace ini belum boleh memakai jadwal. Simpan jadwalnya dalam keadaan nonaktif, atau minta admin Sakala membuka batasnya.'
            : sprintf(
                'Workspace ini sudah punya %d jadwal aktif, dan itu batasnya. Nonaktifkan atau hapus jadwal lain dulu.',
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
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
