<?php

declare(strict_types=1);

namespace App\Exceptions\Schedule;

use App\Enums\ScheduleRunReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Run now" cannot start a run for the reason a slot would be skipped
 * (ScheduleRunLauncher::blockingReason()): nothing is recorded, and the
 * answer names the reason.
 */
final class ScheduleRunRefusedException extends RuntimeException
{
    public const CODE = 'SCHEDULE_RUN_REFUSED';

    public function __construct(
        public readonly ScheduleRunReason $reason,
    ) {
        parent::__construct(match ($reason) {
            ScheduleRunReason::Overlap => 'Jadwal ini masih punya run yang berjalan atau menunggu. Tunggu sampai selesai, atau hentikan run itu dulu.',
            ScheduleRunReason::ProjectSuspended => 'Proyek ini sedang ditangguhkan, jadi jadwalnya tidak bisa dijalankan.',
            ScheduleRunReason::NoLiveDeployment => 'Proyek ini belum punya deployment yang berjalan. Deploy proyek dulu, lalu jalankan jadwal ini lagi.',
            ScheduleRunReason::NodeUnsupported => 'Server yang menjalankan proyek ini belum mendukung jadwal. Tunggu operator memperbarui agent di server, lalu coba lagi.',
            default => $reason->message(),
        });
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'reason' => $this->reason->value,
        ], Response::HTTP_CONFLICT);
    }
}
