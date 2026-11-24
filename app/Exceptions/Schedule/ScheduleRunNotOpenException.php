<?php

declare(strict_types=1);

namespace App\Exceptions\Schedule;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Stop run on a run that already ended. */
final class ScheduleRunNotOpenException extends RuntimeException
{
    public const CODE = 'SCHEDULE_RUN_NOT_OPEN';

    public function __construct()
    {
        parent::__construct('Run ini sudah selesai, jadi tidak ada yang perlu dihentikan.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
        ], Response::HTTP_CONFLICT);
    }
}
