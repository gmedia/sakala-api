<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Exceptions\Schedule\ScheduleRunNotOpenException;
use App\Models\Project;
use App\Models\ScheduleRun;
use App\Models\User;
use App\Services\Schedule\ScheduleRunCanceller;
use App\Support\Project\WorkloadAudit;
use Illuminate\Support\Facades\DB;

/**
 * Stop run: a waiting or unclaimed run ends at once; a running one ends
 * when its node reports the cancellation (ScheduleRunCanceller). Allowed on a
 * suspended project: it only ever stops work. Audited.
 */
final class CancelScheduleRunAction
{
    public function __construct(
        private readonly ScheduleRunCanceller $canceller,
    ) {}

    /** @throws ScheduleRunNotOpenException */
    public function handle(ScheduleRun $run, User $actor): ScheduleRun
    {
        return DB::transaction(function () use ($run, $actor): ScheduleRun {
            if (! $this->canceller->cancel($run)) {
                throw new ScheduleRunNotOpenException;
            }

            /** @var Project $project */
            $project = Project::withTrashed()->findOrFail($run->project_id);

            WorkloadAudit::record($actor, WorkloadAudit::SCHEDULE_RUN_CANCEL_REQUESTED, $project, [
                'schedule_id' => $run->schedule_id,
                'run_id' => $run->id,
                'command_id' => $run->agent_command_id,
            ]);

            return $run->refresh();
        });
    }
}
