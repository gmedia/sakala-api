<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Actions\Agent\FailAgentCommandAction;
use App\Data\Notification\ProjectAlertMailData;
use App\Enums\NotificationCategory;
use App\Enums\ProjectAlert;
use App\Enums\ScheduleHealth;
use App\Enums\SchedulePauseReason;
use App\Enums\ScheduleRunReason;
use App\Enums\ScheduleRunStatus;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Services\Notification\UserNotifier;
use App\Support\Project\WorkloadAudit;

/**
 * What the agent's reports and the command sweep do to a schedule run
 * (ADR-022), and through it to its schedule: the failure count, the health
 * that members are notified about when it changes (never on every failure),
 * and the pause after `pause_after_failures` failures in a row.
 *
 * Callers hold the command row lock; this locks the run, then the schedule.
 * A command whose run is gone (its schedule was deleted) changes nothing.
 */
final class ScheduleRunOutcomeService
{
    /** `error_code` values of a RunJob failure (protocol revision 4, ADR-022). */
    public const JOB_FAILED = 'job_failed';

    public const JOB_TIMEOUT = 'job_timeout';

    public const JOB_IMAGE_MISSING = 'job_image_missing';

    /** Event the agent sends once the job container ended, before the terminal report. */
    public const JOB_FINISHED_EVENT = 'job.finished';

    public function __construct(
        private readonly SchedulePolicy $policy,
        private readonly UserNotifier $notifier,
    ) {}

    /** The node claimed the RunJob: the run is running. */
    public function claimed(AgentCommand $command): void
    {
        $run = $this->lockRun($command);

        if ($run === null || $run->status !== ScheduleRunStatus::Queued) {
            return;
        }

        $run->update(['status' => ScheduleRunStatus::Running, 'started_at' => now()]);
    }

    /**
     * `job.finished`: the structured exit code (null after a timeout), the
     * duration, and whether the kernel killed it for memory. Kept also when
     * the command then fails.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function jobFinished(AgentCommand $command, array $metadata): void
    {
        $run = $this->lockRun($command);

        if ($run === null) {
            return;
        }

        $run->update([
            'exit_code' => is_int($metadata['exit_code'] ?? null) ? $metadata['exit_code'] : null,
            'duration_ms' => is_int($metadata['duration_ms'] ?? null) ? $metadata['duration_ms'] : $run->duration_ms,
            'oom_killed' => is_bool($metadata['oom_killed'] ?? null) ? $metadata['oom_killed'] : $run->oom_killed,
            'timed_out' => is_bool($metadata['timed_out'] ?? null) ? $metadata['timed_out'] : $run->timed_out,
        ]);
    }

    /**
     * `complete`: the command exited with code 0.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function completed(AgentCommand $command, ?array $result): void
    {
        $run = $this->lockRun($command);

        if ($run === null || ! $run->status->isOpen()) {
            return;
        }

        $run->update([
            'status' => ScheduleRunStatus::Succeeded,
            'exit_code' => is_int($result['exit_code'] ?? null) ? $result['exit_code'] : 0,
            'duration_ms' => is_int($result['duration_ms'] ?? null) ? $result['duration_ms'] : $run->duration_ms,
            'oom_killed' => is_bool($result['oom_killed'] ?? null) ? $result['oom_killed'] : false,
            'timed_out' => false,
            'finished_at' => now(),
        ]);

        $this->settle($run);
    }

    /**
     * `fail`: `job_timeout` is a timeout, `runtime_cancelled` a cancellation
     * (Stop run, StopProject, or the agent stopping), anything else a failure
     * with the agent's code and redacted message.
     */
    public function failed(AgentCommand $command, string $errorCode, string $errorMessage): void
    {
        $run = $this->lockRun($command);

        if ($run === null || ! $run->status->isOpen()) {
            return;
        }

        $status = match ($errorCode) {
            self::JOB_TIMEOUT => ScheduleRunStatus::TimedOut,
            FailAgentCommandAction::CODE_CANCELLED => ScheduleRunStatus::Cancelled,
            default => ScheduleRunStatus::Failed,
        };

        $run->update([
            'status' => $status,
            'timed_out' => $status === ScheduleRunStatus::TimedOut ? true : $run->timed_out,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);

        $this->settle($run);
    }

    /**
     * The command sweep closed the RunJob: never claimed before the start
     * deadline (missed), or claimed and then silent past its lease (lost).
     */
    public function expired(AgentCommand $command, bool $leaseExpired): void
    {
        $run = $this->lockRun($command);

        if ($run === null || ! $run->status->isOpen()) {
            return;
        }

        $run->update([
            'status' => $leaseExpired ? ScheduleRunStatus::Lost : ScheduleRunStatus::Missed,
            'reason' => $leaseExpired ? null : ScheduleRunReason::NotStarted,
            'error_code' => $command->error_code,
            'finished_at' => now(),
        ]);

        $this->settle($run);
    }

    /**
     * After a run ended: count the failure or the success on the schedule,
     * notify when its health changes, pause it after too many failures in a
     * row, and keep only the newest `runs_kept` finished runs.
     */
    public function settle(ScheduleRun $run): void
    {
        /** @var ProjectSchedule|null $schedule */
        $schedule = ProjectSchedule::query()->whereKey($run->schedule_id)->lockForUpdate()->first();

        if ($schedule === null) {
            return;
        }

        if ($run->status === ScheduleRunStatus::Succeeded) {
            $wasFailing = $schedule->health === ScheduleHealth::Failing;
            $schedule->update(['health' => ScheduleHealth::Healthy, 'consecutive_failures' => 0]);

            if ($wasFailing) {
                $this->notify($schedule, 'Jadwal berjalan lagi', "Jadwal {$schedule->name} berhasil lagi setelah sebelumnya gagal.", null);
            }
        } elseif ($run->status->isFailure()) {
            $this->countFailure($schedule, $run);
        }

        $this->pruneRuns($schedule);
    }

    /** Delete the finished runs of `$schedule` beyond the newest `runs_kept`. */
    public function pruneRuns(ProjectSchedule $schedule): void
    {
        $keep = ScheduleRun::query()
            ->where('schedule_id', $schedule->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->policy->runsKept())
            ->pluck('id');

        ScheduleRun::query()
            ->where('schedule_id', $schedule->id)
            ->whereNotIn('id', $keep->all())
            ->whereNotIn('status', ScheduleRunStatus::openCases())
            ->delete();
    }

    private function countFailure(ProjectSchedule $schedule, ScheduleRun $run): void
    {
        $failures = $schedule->consecutive_failures + 1;
        $startsFailing = $schedule->health === ScheduleHealth::Healthy;
        $pause = $schedule->enabled && $failures >= $this->policy->pauseAfterFailures();

        $schedule->update([
            'health' => ScheduleHealth::Failing,
            'consecutive_failures' => $failures,
            ...($pause ? [
                'enabled' => false,
                'next_run_at' => null,
                'paused_at' => now(),
                'pause_reason' => SchedulePauseReason::ConsecutiveFailures,
            ] : []),
        ]);

        if ($startsFailing) {
            $this->notify(
                $schedule,
                'Jadwal gagal',
                "Run jadwal {$schedule->name} gagal. Buka riwayat jadwal untuk melihat kode keluar dan log-nya. Sakala memberi tahu lagi saat jadwal ini berhasil.",
                ProjectAlert::ScheduleFailing,
            );
        }

        if (! $pause) {
            return;
        }

        /** @var Project|null $project */
        $project = Project::withTrashed()->find($schedule->project_id);

        if ($project !== null) {
            WorkloadAudit::record(WorkloadAudit::SCHEDULER_ACTOR, WorkloadAudit::SCHEDULE_PAUSED, $project, [
                'schedule_id' => $schedule->id,
                'name' => $schedule->name,
                'reason' => SchedulePauseReason::ConsecutiveFailures->value,
                'consecutive_failures' => $failures,
                'last_run_id' => $run->id,
            ]);
        }

        $this->notify(
            $schedule,
            'Jadwal dijeda',
            "Jadwal {$schedule->name} dijeda setelah {$failures} kali gagal berturut-turut. Perbaiki perintahnya, lalu aktifkan lagi jadwal ini.",
            ProjectAlert::SchedulePaused,
        );
    }

    private function notify(ProjectSchedule $schedule, string $title, string $message, ?ProjectAlert $email): void
    {
        $project = Project::query()->find($schedule->project_id);

        if ($project === null) {
            return;
        }

        $this->notifier->sendToProject($project, NotificationCategory::ProjectIssue, $title, $message);

        if ($email !== null) {
            $this->notifier->emailProject($project, new ProjectAlertMailData(
                alert: $email,
                projectId: $project->id,
                projectName: $project->name,
                summary: $schedule->name,
            ));
        }
    }

    private function lockRun(AgentCommand $command): ?ScheduleRun
    {
        /** @var ScheduleRun|null */
        return ScheduleRun::query()->where('agent_command_id', $command->id)->lockForUpdate()->first();
    }
}
