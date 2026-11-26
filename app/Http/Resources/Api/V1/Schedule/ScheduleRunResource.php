<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Schedule;

use App\Models\ScheduleRun;
use App\Support\Schedule\ScheduleRunFailureMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One run of a schedule (ADR-022). Times are UTC ISO 8601; the Console shows
 * them in the schedule's timezone.
 *
 * @mixin ScheduleRun
 *
 * @property ScheduleRun $resource
 */
final class ScheduleRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;

        return [
            'id' => $run->id,
            'schedule_id' => $run->schedule_id,
            /** `scheduled` for a slot, `manual` for "Run now". */
            'trigger' => $run->trigger,
            /** The slot, in UTC; `null` for a manual run. */
            'scheduled_for' => $run->scheduled_for?->toIso8601ZuluString(),
            /**
             * `waiting` (the workspace runs as many jobs as it may), `queued`, `running`, `succeeded`, `failed`,
             * `timed_out`, `cancelled`, `lost` (the node stopped reporting during the run), `missed` (did not start
             * before its deadline), or `skipped`.
             */
            'status' => $run->status,
            /** Why it was skipped, missed, or waits; `null` otherwise. */
            'reason' => $run->reason,
            /** reason as a sentence for people, in Indonesian; `null` without a reason. */
            'reason_message' => $run->reason?->message(),
            'command' => $run->command,
            'timeout_seconds' => $run->timeout_seconds,
            /** The command's exit code, from the node; `null` before it ended, after a timeout, or when it never ran. */
            'exit_code' => $run->exit_code,
            /** From the container's start until it exited, in milliseconds. */
            'duration_ms' => $run->duration_ms,
            /** Whether the kernel killed the command for memory. */
            'oom_killed' => $run->oom_killed,
            'timed_out' => $run->timed_out,
            /** The node's or control plane's failure code, for example `job_failed`; `null` unless it failed or was stopped. */
            'error_code' => $run->error_code,
            /** What the failure means and what to do, in Indonesian; `null` without a failure code. */
            'error_summary' => ScheduleRunFailureMessage::for($run),
            /** The live deployment whose image the run used. */
            'deployment_id' => $run->deployment_id,
            /** The run must start before this, or it is missed. */
            'deadline_at' => $run->deadline_at?->toIso8601ZuluString(),
            /** When the node took the run. */
            'started_at' => $run->started_at?->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->toIso8601ZuluString(),
            'created_at' => $run->created_at?->toIso8601ZuluString(),
        ];
    }
}
