<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\ProjectMetricsData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectMetricsRange;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Models\ProjectMetricHour;
use App\Models\ProjectMetricMinute;
use App\Support\Metrics\RestartCounter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Metric series of a project for GET app/projects/{project}/metrics.
 *
 * Every stored row (minute or hour) is one replica of one deployment. For each
 * source bucket the replicas are summed: CPU and memory averages add up, and
 * so do the maxima, which makes the total maximum an upper bound (replicas
 * rarely peak in the same minute). A step longer than the source bucket then
 * takes the mean of those totals as its average and the largest total as its
 * maximum. Restarts are counted per replica from its cumulative counter and
 * summed per step, plus every restart a member asked for that succeeded
 * (RestartProjectAction): one per replica of the restarted deployment, in the
 * step of its completion. A step without any row has no CPU, memory, or
 * replicas point: gaps stay gaps. Restarts are events, not samples, so a step
 * that has restarts keeps its restarts point even without a row; a restart
 * that finished before the new containers were first sampled still counts.
 *
 * Those restarts come from `agent_commands`, which are kept until the project
 * is purged, so they cover every range. A restart that recreates containers
 * resets their counter, which the counter rule reads as zero, and one that
 * keeps them does not move Docker's counter: neither is counted twice.
 */
final class GetProjectMetricsAction
{
    /**
     * Minute rows read before the range give each replica's restart counter
     * a starting reading, so a restart in the first minute of the range is
     * counted.
     */
    public const RESTART_BASELINE_SECONDS = 600;

    private const CPU_PRECISION = 2;

    public function handle(Project $project, ProjectMetricsRange $range, bool $perReplica): ProjectMetricsData
    {
        $now = CarbonImmutable::now();
        $step = $range->stepSeconds();
        $lastStep = intdiv($now->getTimestamp(), $step) * $step;
        $firstStep = $lastStep - $range->seconds() + $step;

        $total = [];
        $byReplica = [];
        $totalRestarts = [];
        $replicaRestarts = [];
        $series = null;
        $previousRestarts = null;

        foreach ($this->rows($project, $range, $firstStep, $now) as $row) {
            $rowSeries = $row->deployment_id.'|'.$row->replica;

            if ($rowSeries !== $series) {
                $series = $rowSeries;
                $previousRestarts = null;
            }

            $restarts = $row instanceof ProjectMetricHour
                ? $row->restarts
                : RestartCounter::increase($previousRestarts, $row->restart_count);
            $previousRestarts = $row->restart_count;
            $bucket = $row->bucket->getTimestamp();

            if ($bucket < $firstStep) {
                continue;
            }

            $stepStart = intdiv($bucket, $step) * $step;
            $this->addRow($total, $bucket, $row);
            $totalRestarts[$stepStart] = ($totalRestarts[$stepStart] ?? 0) + $restarts;

            if ($perReplica) {
                $byReplica[$row->replica] ??= [];
                $this->addRow($byReplica[$row->replica], $bucket, $row);
                $replicaRestarts[$row->replica][$stepStart] = ($replicaRestarts[$row->replica][$stepStart] ?? 0) + $restarts;
            }
        }

        foreach ($this->userRestarts($project, CarbonImmutable::createFromTimestampUTC($firstStep), $now) as $restart) {
            $stepStart = intdiv($restart['completed_at'], $step) * $step;
            $totalRestarts[$stepStart] = ($totalRestarts[$stepStart] ?? 0) + $restart['replicas'];

            if ($perReplica) {
                for ($replica = 0; $replica < $restart['replicas']; $replica++) {
                    $replicaRestarts[$replica][$stepStart] = ($replicaRestarts[$replica][$stepStart] ?? 0) + 1;
                }
            }
        }

        $totalSeries = $this->series($total, $totalRestarts, $step);

        return new ProjectMetricsData(
            range: $range,
            stepSeconds: $step,
            cpuPercent: $totalSeries['cpu_percent'],
            memoryBytes: $totalSeries['memory_bytes'],
            memoryLimitBytes: $this->latestMemoryLimit($total),
            replicas: $totalSeries['replicas'],
            restarts: $totalSeries['restarts'],
            perReplica: $perReplica ? $this->perReplica($byReplica, $replicaRestarts, $step) : null,
        );
    }

    /**
     * Rows of the range ordered by replica, then time; minute rows start a
     * little early for the restart baseline.
     *
     * @return Collection<int, ProjectMetricMinute>|Collection<int, ProjectMetricHour>
     */
    private function rows(Project $project, ProjectMetricsRange $range, int $firstStep, CarbonImmutable $now): Collection
    {
        $from = CarbonImmutable::createFromTimestampUTC($firstStep);

        if ($range->readsHourlyRollups()) {
            return $this->inRange(ProjectMetricHour::query(), $project, $from, $now)->get();
        }

        return $this->inRange(ProjectMetricMinute::query(), $project, $from->subSeconds(self::RESTART_BASELINE_SECONDS), $now)->get();
    }

    /**
     * @template TModel of ProjectMetricMinute|ProjectMetricHour
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inRange(Builder $query, Project $project, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query
            ->where('project_id', $project->id)
            ->where('bucket', '>=', $from)
            ->where('bucket', '<=', $to)
            ->orderBy('deployment_id')
            ->orderBy('replica')
            ->orderBy('bucket');
    }

    /**
     * Restarts a member asked for that succeeded in the range: when, as a Unix
     * timestamp, and how many replicas the restarted deployment runs. Served
     * by the `(project_id, type, completed_at)` index of `agent_commands`.
     *
     * @return list<array{completed_at: int, replicas: int}>
     */
    private function userRestarts(Project $project, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values(AgentCommand::query()
            ->with('deployment:id,replicas')
            ->where('project_id', $project->id)
            ->where('type', AgentCommandType::RestartProject)
            ->where('completed_at', '>=', $from)
            ->where('completed_at', '<=', $to)
            ->where('status', AgentCommandStatus::Succeeded)
            ->get(['id', 'deployment_id', 'completed_at'])
            ->filter(static fn (AgentCommand $command): bool => $command->completed_at !== null)
            ->map(static fn (AgentCommand $command): array => [
                'completed_at' => (int) $command->completed_at?->getTimestamp(),
                'replicas' => $command->deployment !== null ? $command->deployment->replicas : Project::DEFAULT_REPLICAS,
            ])
            ->all());
    }

    /**
     * Add one replica's row to the totals of its source bucket.
     *
     * @param  array<int, array{cpu_avg: float, cpu_max: float, memory_avg: float, memory_max: int, replicas: int, limit: int, unlimited: bool}>  $buckets
     */
    private function addRow(array &$buckets, int $bucket, ProjectMetricMinute|ProjectMetricHour $row): void
    {
        $totals = $buckets[$bucket] ?? ['cpu_avg' => 0.0, 'cpu_max' => 0.0, 'memory_avg' => 0.0, 'memory_max' => 0, 'replicas' => 0, 'limit' => 0, 'unlimited' => false];

        $buckets[$bucket] = [
            'cpu_avg' => $totals['cpu_avg'] + $row->cpu_percent_sum / $row->sample_count,
            'cpu_max' => $totals['cpu_max'] + $row->cpu_percent_max,
            'memory_avg' => $totals['memory_avg'] + $row->memory_bytes_sum / $row->sample_count,
            'memory_max' => $totals['memory_max'] + $row->memory_bytes_max,
            'replicas' => $totals['replicas'] + 1,
            'limit' => $totals['limit'] + ($row->memory_limit_bytes ?? 0),
            'unlimited' => $totals['unlimited'] || $row->memory_limit_bytes === null,
        ];
    }

    /**
     * Fold source buckets into steps.
     *
     * @param  array<int, array{cpu_avg: float, cpu_max: float, memory_avg: float, memory_max: int, replicas: int, limit: int, unlimited: bool}>  $buckets
     * @param  array<int, int>  $restarts  Keyed by step start.
     * @return array{cpu_percent: list<array{t: int, avg: float, max: float}>, memory_bytes: list<array{t: int, avg: int, max: int}>, replicas: list<array{t: int, count: int}>, restarts: list<array{t: int, count: int}>}
     */
    private function series(array $buckets, array $restarts, int $step): array
    {
        $steps = [];

        foreach ($buckets as $bucket => $totals) {
            $stepStart = intdiv($bucket, $step) * $step;
            $current = $steps[$stepStart] ?? ['buckets' => 0, 'cpu_avg' => 0.0, 'cpu_max' => 0.0, 'memory_avg' => 0.0, 'memory_max' => 0, 'replicas' => 0];

            $steps[$stepStart] = [
                'buckets' => $current['buckets'] + 1,
                'cpu_avg' => $current['cpu_avg'] + $totals['cpu_avg'],
                'cpu_max' => max($current['cpu_max'], $totals['cpu_max']),
                'memory_avg' => $current['memory_avg'] + $totals['memory_avg'],
                'memory_max' => max($current['memory_max'], $totals['memory_max']),
                'replicas' => max($current['replicas'], $totals['replicas']),
            ];
        }

        ksort($steps);
        $series = ['cpu_percent' => [], 'memory_bytes' => [], 'replicas' => [], 'restarts' => []];

        foreach ($steps as $stepStart => $totals) {
            $series['cpu_percent'][] = [
                't' => $stepStart,
                'avg' => round($totals['cpu_avg'] / $totals['buckets'], self::CPU_PRECISION),
                'max' => round($totals['cpu_max'], self::CPU_PRECISION),
            ];
            $series['memory_bytes'][] = [
                't' => $stepStart,
                'avg' => (int) round($totals['memory_avg'] / $totals['buckets']),
                'max' => $totals['memory_max'],
            ];
            $series['replicas'][] = ['t' => $stepStart, 'count' => $totals['replicas']];
        }

        $series['restarts'] = $this->restartPoints($steps, $restarts);

        return $series;
    }

    /**
     * A point for every step with a row, zero when nothing restarted, and for
     * every step with restarts, with or without a row. A step without a row
     * and without restarts stays a gap.
     *
     * @param  array<int, mixed>  $steps  Steps with a row, keyed by step start.
     * @param  array<int, int>  $restarts  Keyed by step start.
     * @return list<array{t: int, count: int}>
     */
    private function restartPoints(array $steps, array $restarts): array
    {
        $counts = array_fill_keys(array_keys($steps), 0);

        foreach ($restarts as $stepStart => $count) {
            if ($count > 0 || array_key_exists($stepStart, $counts)) {
                $counts[$stepStart] = $count;
            }
        }

        ksort($counts);
        $points = [];

        foreach ($counts as $stepStart => $count) {
            $points[] = ['t' => $stepStart, 'count' => $count];
        }

        return $points;
    }

    /**
     * The summed limit of the latest source bucket, or null when it has no
     * data or any replica in it runs without a limit.
     *
     * @param  array<int, array{limit: int, unlimited: bool}>  $buckets
     */
    private function latestMemoryLimit(array $buckets): ?int
    {
        if ($buckets === []) {
            return null;
        }

        $latest = $buckets[max(array_keys($buckets))];

        return $latest['unlimited'] ? null : $latest['limit'];
    }

    /**
     * @param  array<int, array<int, array{cpu_avg: float, cpu_max: float, memory_avg: float, memory_max: int, replicas: int, limit: int, unlimited: bool}>>  $byReplica
     * @param  array<int, array<int, int>>  $restarts
     * @return list<array{replica: int, cpu_percent: list<array{t: int, avg: float, max: float}>, memory_bytes: list<array{t: int, avg: int, max: int}>, restarts: list<array{t: int, count: int}>}>
     */
    private function perReplica(array $byReplica, array $restarts, int $step): array
    {
        // A replica restarted in the range but without a row in it still
        // gets its restarts; its CPU and memory series are empty.
        $indexes = array_unique([...array_keys($byReplica), ...array_keys($restarts)]);
        sort($indexes);
        $replicas = [];

        foreach ($indexes as $replica) {
            $series = $this->series($byReplica[$replica] ?? [], $restarts[$replica] ?? [], $step);
            $replicas[] = [
                'replica' => $replica,
                'cpu_percent' => $series['cpu_percent'],
                'memory_bytes' => $series['memory_bytes'],
                'restarts' => $series['restarts'],
            ];
        }

        return $replicas;
    }
}
