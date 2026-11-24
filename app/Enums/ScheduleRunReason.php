<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a run was skipped, missed, or waited (`schedule_runs.reason`). The
 * Console shows message(); nothing here names a node or an internal ID.
 */
enum ScheduleRunReason: string
{
    /** The schedule's previous run was still open when the slot came. */
    case Overlap = 'overlap';

    /** The workspace already ran `max_concurrent_job_runs` jobs. */
    case ConcurrencyLimit = 'concurrency_limit';

    case ProjectSuspended = 'project_suspended';

    case ProjectDeleted = 'project_deleted';

    /** The project has no deployment on a node, so there is no image to run. */
    case NoLiveDeployment = 'no_live_deployment';

    /** The node of the live deployment does not advertise `scheduled-jobs`. */
    case NodeUnsupported = 'node_unsupported';

    /** The dispatcher reached the slot after its start deadline, for example while the API was down. */
    case DispatcherLate = 'dispatcher_late';

    /** The node did not claim the RunJob before the start deadline: offline or busy. */
    case NotStarted = 'not_started';

    public function message(): string
    {
        return match ($this) {
            self::Overlap => 'Dilewati karena run sebelumnya dari jadwal ini masih berjalan.',
            self::ConcurrencyLimit => 'Workspace ini sedang menjalankan job sebanyak batasnya, jadi run ini menunggu atau terlewat.',
            self::ProjectSuspended => 'Dilewati karena proyek ini sedang ditangguhkan.',
            self::ProjectDeleted => 'Dilewati karena proyek ini sudah dihapus.',
            self::NoLiveDeployment => 'Dilewati karena proyek ini belum punya deployment yang berjalan. Deploy proyek dulu.',
            self::NodeUnsupported => 'Dilewati karena server yang menjalankan proyek ini belum mendukung jadwal. Tunggu operator memperbarui agent di server.',
            self::DispatcherLate => 'Terlewat karena penjadwal Sakala tidak sempat memulainya sebelum batas mulai, misalnya saat Sakala sedang gangguan.',
            self::NotStarted => 'Terlewat karena server proyek tidak memulainya sebelum batas mulai, misalnya karena sedang offline atau sibuk.',
        };
    }
}
