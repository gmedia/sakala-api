<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Schedule;

use App\Models\ProjectSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A schedule of a project (ADR-022). Times are UTC ISO 8601; `timezone` is
 * how the cron expression is read and how the Console shows them.
 *
 * @mixin ProjectSchedule
 *
 * @property ProjectSchedule $resource
 */
final class ProjectScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $schedule = $this->resource;

        return [
            'id' => $schedule->id,
            'project_id' => $schedule->project_id,
            'name' => $schedule->name,
            'command' => $schedule->command,
            /** Five fields: minute, hour, day of month, month, day of week. */
            'cron_expression' => $schedule->cron_expression,
            'timezone' => $schedule->timezone,
            'timeout_seconds' => $schedule->timeout_seconds,
            'enabled' => $schedule->enabled,
            /** The next slot that has not been handled, in UTC; `null` while disabled. */
            'next_run_at' => $schedule->next_run_at?->toIso8601ZuluString(),
            /** `failing` after a failed, timed out, or lost run, until one succeeds. */
            'health' => $schedule->health,
            /** Failed, timed out, or lost runs in a row. */
            'consecutive_failures' => $schedule->consecutive_failures,
            /** When the control plane disabled the schedule by itself; `null` otherwise. Enabling it clears this. */
            'paused_at' => $schedule->paused_at?->toIso8601ZuluString(),
            /** `consecutive_failures`, or `null`. */
            'pause_reason' => $schedule->pause_reason,
            /**
             * The newest run, whatever its outcome; `null` before the first.
             *
             * @var ScheduleRunResource|null
             */
            'latest_run' => $this->whenLoaded('latestRun', fn (): ?ScheduleRunResource => $schedule->latestRun === null ? null : new ScheduleRunResource($schedule->latestRun)),
            'created_at' => $schedule->created_at?->toIso8601ZuluString(),
            'updated_at' => $schedule->updated_at?->toIso8601ZuluString(),
        ];
    }
}
