<?php

declare(strict_types=1);

namespace App\Support\Schedule;

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Actions\Agent\FailAgentCommandAction;
use App\Models\ScheduleRun;
use App\Services\Schedule\ScheduleRunCanceller;
use App\Services\Schedule\ScheduleRunOutcomeService;
use App\Support\Valkey\ValkeyCommandFailure;

/**
 * What a run's ending means for a member, in plain Indonesian text without
 * markup, from the agent's `error_code` (ADR-022) or the control plane's own.
 * The agent's English message stays in `error_message` for the details.
 */
final class ScheduleRunFailureMessage
{
    /** `error_code` of a node that was full. */
    private const CAPACITY_EXCEEDED = 'runtime_capacity_exceeded';

    /** `error_code` of a payload the node refused. */
    private const INVALID_COMMAND = 'invalid_runtime_command';

    /** `error_code` of a `PORT` variable that differs from the deployment's port. */
    private const PORT_CONFLICT = 'runtime_port_conflict';

    /** Null for a run that did not fail, was skipped, or was missed (its `reason` explains those). */
    public static function for(ScheduleRun $run): ?string
    {
        if ($run->error_code === null) {
            return null;
        }

        return match ($run->error_code) {
            ScheduleRunOutcomeService::JOB_FAILED => $run->oom_killed === true
                ? 'Perintah kehabisan memori dan dihentikan. Kurangi pemakaian memori perintah ini, atau naikkan memori proyek saat deploy.'
                : 'Perintah keluar dengan kode selain 0. Kode keluarnya tertulis di run ini; buka log run untuk melihat error-nya.',
            ScheduleRunOutcomeService::JOB_TIMEOUT => 'Perintah melewati batas waktu jadwal dan dihentikan. Percepat perintahnya atau naikkan batas waktu jadwal, paling lama 10 menit.',
            ScheduleRunOutcomeService::JOB_IMAGE_MISSING => 'Image deployment yang sedang berjalan tidak ada di server. Deploy ulang proyek ini, lalu jalankan jadwalnya lagi.',
            self::CAPACITY_EXCEEDED => 'Server Sakala sedang penuh, jadi run ini tidak dimulai. Ini bukan kesalahan di aplikasimu; run berikutnya dicoba seperti biasa.',
            self::INVALID_COMMAND => 'Server Sakala menolak perintah run ini. Pastikan perintahnya satu baris, lalu coba lagi.',
            ValkeyCommandFailure::NETWORK_UNAVAILABLE => 'Jaringan Valkey yang dipakai versi yang sedang berjalan tidak ditemukan di server, jadi run ini tidak dimulai. Ini bukan kesalahan di aplikasimu; deploy ulang proyek ini, lalu jalankan jadwalnya lagi. Kalau terulang, hubungi admin Sakala.',
            self::PORT_CONFLICT => 'Environment variable PORT di proyek ini berbeda dengan port yang dipakai Sakala. Hapus atau samakan PORT, deploy ulang, lalu jalankan jadwalnya lagi.',
            FailAgentCommandAction::CODE_CANCELLED, ScheduleRunCanceller::CANCELLED_BEFORE_CLAIM => 'Run ini dihentikan sebelum selesai.',
            ExpireAgentCommandsAction::CODE_LEASE_EXPIRED => 'Server berhenti melapor saat run ini berjalan, jadi hasilnya tidak diketahui. Pastikan perintahnya aman dijalankan ulang, lalu jalankan lagi bila perlu.',
            ExpireAgentCommandsAction::CODE_QUEUE_EXPIRED => 'Server proyek tidak memulai run ini sebelum batas mulai.',
            default => 'Run ini gagal karena kesalahan yang belum dikenali. Buka log run, dan kirim kode kegagalannya ke admin Sakala bila berulang.',
        };
    }
}
