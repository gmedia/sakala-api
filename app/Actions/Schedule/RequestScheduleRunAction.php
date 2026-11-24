<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Enums\ScheduleRunTrigger;
use App\Exceptions\Schedule\ScheduleRunRefusedException;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Models\User;
use App\Services\Schedule\SchedulePolicy;
use App\Services\Schedule\ScheduleRunLauncher;
use App\Support\Project\WorkloadAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Run now": one run of the schedule outside its slots, under the same rules
 * as a slot (ScheduleRunLauncher). A reason that would skip a slot refuses
 * the request instead, and records nothing; a full workspace makes the run
 * wait until the start deadline like a slot. Works on a disabled or paused
 * schedule too, to try a fix. Audited.
 */
final class RequestScheduleRunAction
{
    public function __construct(
        private readonly ScheduleRunLauncher $launcher,
        private readonly SchedulePolicy $policy,
    ) {}

    /** @throws ScheduleRunRefusedException */
    public function handle(ProjectSchedule $schedule, User $actor): ScheduleRun
    {
        return DB::transaction(function () use ($schedule, $actor): ScheduleRun {
            $workspace = $this->launcher->lockWorkspace($schedule);
            $locked = $this->launcher->lockSchedule($schedule->id) ?? $schedule;

            $reason = $this->launcher->blockingReason($locked);

            if ($reason !== null) {
                throw new ScheduleRunRefusedException($reason);
            }

            $run = $this->launcher->launch(
                $locked,
                $this->launcher->newRun($locked, ScheduleRunTrigger::Manual, null, $this->policy->manualDeadline(CarbonImmutable::now()), $actor->id),
                $workspace,
            );

            /** @var Project $project */
            $project = Project::query()->findOrFail($locked->project_id);

            WorkloadAudit::record($actor, WorkloadAudit::SCHEDULE_RUN_REQUESTED, $project, [
                'schedule_id' => $locked->id,
                'run_id' => $run->id,
                'command_id' => $run->agent_command_id,
                'status' => $run->status->value,
            ]);

            return $run;
        });
    }
}
