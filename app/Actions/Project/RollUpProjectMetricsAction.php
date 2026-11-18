<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Actions\Agent\RecordWorkloadMetricsAction;
use App\Models\ProjectMetricHour;
use App\Models\ProjectMetricMinute;
use App\Support\Metrics\RestartCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Fold minute rows into hourly rows, then prune both past their retention.
 *
 * Every run recomputes the previous and the current hour from scratch and
 * replaces their hourly rows, so it is idempotent, a late sample (younger
 * than RecordWorkloadMetricsAction::MAX_SAMPLE_AGE_SECONDS) still reaches its
 * hour, and the current hour fills in as it goes. After the scheduler was
 * down, it starts from the latest hourly row instead, as far back as minute
 * rows are kept whole.
 */
final class RollUpProjectMetricsAction
{
    /** Rows per upsert and per prune delete, to keep statements small. */
    public const BATCH_SIZE = 500;

    /**
     * @return array{hours: int, pruned_minutes: int, pruned_hours: int}
     */
    public function handle(int $minuteRetentionHours, int $hourlyRetentionDays): array
    {
        $now = CarbonImmutable::now();
        $start = $this->windowStart($now, $minuteRetentionHours);
        $hours = $this->rollUp($start, $now->startOfHour()->addHour());

        return [
            'hours' => $hours,
            'pruned_minutes' => $this->prune(new ProjectMetricMinute, $now->subHours($minuteRetentionHours)),
            'pruned_hours' => $this->prune(new ProjectMetricHour, $now->subDays($hourlyRetentionDays)),
        ];
    }

    private function windowStart(CarbonImmutable $now, int $minuteRetentionHours): CarbonImmutable
    {
        // The oldest hour whose minute rows are all still kept: recomputing a
        // partly pruned hour would overwrite a complete hourly row.
        $retained = $now->subHours($minuteRetentionHours);
        $oldestWhole = $retained->equalTo($retained->startOfHour()) ? $retained : $retained->startOfHour()->addHour();

        $previousHour = $now->startOfHour()->subHour();
        $latest = ProjectMetricHour::query()->max('bucket');

        if ($latest === null) {
            $start = $oldestWhole;
        } else {
            $latestHour = CarbonImmutable::parse($latest)->utc();
            $start = $latestHour->lessThan($previousHour) ? $latestHour : $previousHour;
        }

        return $start->max($oldestWhole);
    }

    /**
     * The minute rows from an hour before `$start` give each replica's
     * restart counter a starting reading; only hours from `$start` are
     * written.
     */
    private function rollUp(CarbonImmutable $start, CarbonImmutable $end): int
    {
        $minutes = ProjectMetricMinute::query()
            ->where('bucket', '>=', $start->subHour())
            ->where('bucket', '<', $end)
            ->orderBy('deployment_id')
            ->orderBy('replica')
            ->orderBy('bucket')
            ->cursor();

        $model = new ProjectMetricHour;
        $hours = [];
        $series = null;
        $previousRestarts = null;
        $written = 0;

        foreach ($minutes as $minute) {
            $minuteSeries = $minute->deployment_id.'|'.$minute->replica;

            if ($minuteSeries !== $series) {
                $series = $minuteSeries;
                $previousRestarts = null;
            }

            $restarts = RestartCounter::increase($previousRestarts, $minute->restart_count);
            $previousRestarts = $minute->restart_count;
            $hour = CarbonImmutable::instance($minute->bucket)->utc()->startOfHour();

            if ($hour->lessThan($start)) {
                continue;
            }

            $key = $minuteSeries.'|'.$hour->getTimestamp();
            $row = $hours[$key] ?? null;

            $hours[$key] = $row === null ? [
                'project_id' => $minute->project_id,
                'deployment_id' => $minute->deployment_id,
                'replica' => $minute->replica,
                'bucket' => $model->fromDateTime($hour),
                'sample_count' => $minute->sample_count,
                'cpu_percent_sum' => $minute->cpu_percent_sum,
                'cpu_percent_max' => $minute->cpu_percent_max,
                'memory_bytes_sum' => $minute->memory_bytes_sum,
                'memory_bytes_max' => $minute->memory_bytes_max,
                'memory_limit_bytes' => $minute->memory_limit_bytes,
                'restart_count' => $minute->restart_count,
                'restarts' => $restarts,
                'last_sampled_at' => $model->fromDateTime($minute->last_sampled_at),
            ] : [
                ...$row,
                'sample_count' => $row['sample_count'] + $minute->sample_count,
                'cpu_percent_sum' => $row['cpu_percent_sum'] + $minute->cpu_percent_sum,
                'cpu_percent_max' => max($row['cpu_percent_max'], $minute->cpu_percent_max),
                'memory_bytes_sum' => $row['memory_bytes_sum'] + $minute->memory_bytes_sum,
                'memory_bytes_max' => max($row['memory_bytes_max'], $minute->memory_bytes_max),
                // Rows come oldest first, so the latest minute wins.
                'memory_limit_bytes' => $minute->memory_limit_bytes,
                'restart_count' => $minute->restart_count,
                'restarts' => $row['restarts'] + $restarts,
                'last_sampled_at' => $model->fromDateTime($minute->last_sampled_at),
            ];

            // Rows come by replica, then by time, so every row but the one
            // being filled is final.
            if (count($hours) > self::BATCH_SIZE) {
                $written += $this->flush($hours, keep: $key);
            }
        }

        return $written + $this->flush($hours);
    }

    /**
     * Write every finished hourly row, replacing what was stored. With `keep`,
     * the row still being filled stays in memory.
     *
     * @param  array<string, array<string, mixed>>  $hours
     */
    private function flush(array &$hours, ?string $keep = null): int
    {
        $kept = $keep !== null ? [$keep => $hours[$keep]] : [];
        $rows = array_values(array_diff_key($hours, $kept));
        $hours = $kept;

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            ProjectMetricHour::query()->upsert($chunk, ProjectMetricHour::UNIQUE_BY);
        }

        return count($rows);
    }

    private function prune(Model $model, CarbonImmutable $cutoff): int
    {
        $pruned = 0;

        do {
            $deleted = DB::table($model->getTable())
                ->where('bucket', '<', $model->fromDateTime($cutoff))
                ->limit(self::BATCH_SIZE)
                ->delete();

            $pruned += $deleted;
        } while ($deleted >= self::BATCH_SIZE);

        return $pruned;
    }
}
