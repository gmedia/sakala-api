<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\WorkloadMetricsData;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectMetricMinute;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Merge a heartbeat's metric samples into per-minute rows. Only the node that
 * serves a project's active deployment speaks for it, the same rule as the
 * traffic list; samples for anything else, and stale ones, are dropped without
 * failing the heartbeat. A sample the API already counted (same replica, same
 * second or older) is not counted again, so a node that repeats its latest
 * sample does not skew the averages. Heartbeats of one node are serialised by
 * the node row lock, so the read-then-upsert below does not race itself.
 *
 * Costs one project read, one minute-row read, and one upsert per heartbeat,
 * whatever the length of the list.
 */
final class RecordWorkloadMetricsAction
{
    /**
     * Samples older than this are stale and dropped. It must stay below an
     * hour: `projects:roll-up-metrics` recomputes the previous and the
     * current hour, so a late sample still reaches its hourly row.
     */
    public const MAX_SAMPLE_AGE_SECONDS = 600;

    public function handleWithinTransaction(AgentNode $agent, WorkloadMetricsData $report): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $samples = $this->freshSamples($report, $now);

        if ($samples === []) {
            return;
        }

        $projectByDeployment = $this->servedProjectsByDeployment($agent, $samples);
        $rows = [];

        foreach ($samples as $sample) {
            if (($projectByDeployment[$sample['deployment_id']] ?? null) !== $sample['project_id']) {
                continue;
            }

            $this->mergeSample($rows, $sample);
        }

        $rows = $this->withoutCountedSamples($rows);

        if ($rows === []) {
            return;
        }

        $model = new ProjectMetricMinute;

        ProjectMetricMinute::query()->upsert(
            array_map(static fn (array $row): array => [
                ...$row,
                'bucket' => $model->fromDateTime($row['bucket']),
                'last_sampled_at' => $model->fromDateTime($row['last_sampled_at']),
            ], array_values($rows)),
            ProjectMetricMinute::UNIQUE_BY,
            $this->mergeExpressions(),
        );
    }

    /**
     * Samples young enough to keep, oldest first, with times cut to the
     * second (the precision stored) and a node clock running ahead pulled
     * back to now.
     *
     * @return list<array{project_id: string, deployment_id: string, replica: int, sampled_at: CarbonImmutable, cpu_percent: float, memory_bytes: int, memory_limit_bytes: int|null, restart_count: int}>
     */
    private function freshSamples(WorkloadMetricsData $report, CarbonImmutable $now): array
    {
        $oldest = $now->subSeconds(self::MAX_SAMPLE_AGE_SECONDS);
        $samples = [];

        foreach ($report->samples as $sample) {
            $sampledAt = $sample['sampled_at']->startOfSecond()->min($now);

            if ($sampledAt->lessThan($oldest)) {
                continue;
            }

            $samples[] = [...$sample, 'sampled_at' => $sampledAt];
        }

        usort($samples, static fn (array $a, array $b): int => $a['sampled_at'] <=> $b['sampled_at']);

        return $samples;
    }

    /**
     * Lowercase project ID keyed by lowercase deployment ID, for the reported
     * deployments that are their project's active deployment on this node.
     *
     * @param  list<array{deployment_id: string}>  $samples
     * @return array<string, string>
     */
    private function servedProjectsByDeployment(AgentNode $agent, array $samples): array
    {
        $projects = Project::query()
            ->whereIn('active_deployment_id', array_values(array_unique(array_column($samples, 'deployment_id'))))
            ->whereIn('active_deployment_id', Deployment::query()->select('id')->where('agent_node_id', $agent->id))
            ->get(['id', 'active_deployment_id']);

        $byDeployment = [];

        foreach ($projects as $project) {
            $byDeployment[mb_strtolower((string) $project->active_deployment_id)] = mb_strtolower($project->id);
        }

        return $byDeployment;
    }

    /**
     * Fold one sample into the row of its replica and minute. Samples arrive
     * oldest first, so the last one sets the memory limit; a repeat of the
     * same second is skipped.
     *
     * @param  array<string, array{project_id: string, deployment_id: string, replica: int, bucket: CarbonImmutable, sample_count: int, cpu_percent_sum: float, cpu_percent_max: float, memory_bytes_sum: int, memory_bytes_max: int, memory_limit_bytes: int|null, restart_count: int, last_sampled_at: CarbonImmutable}>  $rows
     * @param  array{project_id: string, deployment_id: string, replica: int, sampled_at: CarbonImmutable, cpu_percent: float, memory_bytes: int, memory_limit_bytes: int|null, restart_count: int}  $sample
     */
    private function mergeSample(array &$rows, array $sample): void
    {
        $bucket = $sample['sampled_at']->startOfMinute();
        $key = $sample['deployment_id'].'|'.$sample['replica'].'|'.$bucket->getTimestamp();
        $row = $rows[$key] ?? null;

        if ($row === null) {
            $rows[$key] = [
                'project_id' => $sample['project_id'],
                'deployment_id' => $sample['deployment_id'],
                'replica' => $sample['replica'],
                'bucket' => $bucket,
                'sample_count' => 1,
                'cpu_percent_sum' => $sample['cpu_percent'],
                'cpu_percent_max' => $sample['cpu_percent'],
                'memory_bytes_sum' => $sample['memory_bytes'],
                'memory_bytes_max' => $sample['memory_bytes'],
                'memory_limit_bytes' => $sample['memory_limit_bytes'],
                'restart_count' => $sample['restart_count'],
                'last_sampled_at' => $sample['sampled_at'],
            ];

            return;
        }

        if ($sample['sampled_at']->lessThanOrEqualTo($row['last_sampled_at'])) {
            return;
        }

        $rows[$key] = [
            ...$row,
            'sample_count' => $row['sample_count'] + 1,
            'cpu_percent_sum' => $row['cpu_percent_sum'] + $sample['cpu_percent'],
            'cpu_percent_max' => max($row['cpu_percent_max'], $sample['cpu_percent']),
            'memory_bytes_sum' => $row['memory_bytes_sum'] + $sample['memory_bytes'],
            'memory_bytes_max' => max($row['memory_bytes_max'], $sample['memory_bytes']),
            'memory_limit_bytes' => $sample['memory_limit_bytes'],
            'restart_count' => max($row['restart_count'], $sample['restart_count']),
            'last_sampled_at' => $sample['sampled_at'],
        ];
    }

    /**
     * Drop rows whose samples are all already stored. A row that mixes
     * counted and new samples cannot be split here; the node sends each
     * sample once or repeats only its latest, so that does not happen.
     *
     * @template TRow of array{deployment_id: string, replica: int, bucket: CarbonImmutable, last_sampled_at: CarbonImmutable}
     *
     * @param  array<string, TRow>  $rows
     * @return array<string, TRow>
     */
    private function withoutCountedSamples(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $model = new ProjectMetricMinute;
        $stored = ProjectMetricMinute::query()
            ->whereIn('deployment_id', array_values(array_unique(array_column($rows, 'deployment_id'))))
            ->whereIn('bucket', array_values(array_unique(array_map(
                static fn (array $row): string => $model->fromDateTime($row['bucket']),
                $rows,
            ))))
            ->get(['deployment_id', 'replica', 'bucket', 'last_sampled_at']);

        foreach ($stored as $minute) {
            $key = mb_strtolower($minute->deployment_id).'|'.$minute->replica.'|'.$minute->bucket->getTimestamp();

            if (isset($rows[$key]) && $rows[$key]['last_sampled_at']->getTimestamp() <= $minute->last_sampled_at->getTimestamp()) {
                unset($rows[$key]);
            }
        }

        return $rows;
    }

    /**
     * On conflict the stored row and the new one merge: counts and sums add
     * up, maxima keep the larger, and the newer sample sets the limit.
     * Identifiers are quoted the standard way, which PostgreSQL and SQLite
     * share.
     *
     * @return array<string, ExpressionContract>
     */
    private function mergeExpressions(): array
    {
        $greatest = DB::connection()->getDriverName() === 'sqlite' ? 'max' : 'greatest';

        return [
            'sample_count' => new Expression(self::stored('sample_count').' + '.self::incoming('sample_count')),
            'cpu_percent_sum' => new Expression(self::stored('cpu_percent_sum').' + '.self::incoming('cpu_percent_sum')),
            'cpu_percent_max' => new Expression($greatest.'('.self::stored('cpu_percent_max').', '.self::incoming('cpu_percent_max').')'),
            'memory_bytes_sum' => new Expression(self::stored('memory_bytes_sum').' + '.self::incoming('memory_bytes_sum')),
            'memory_bytes_max' => new Expression($greatest.'('.self::stored('memory_bytes_max').', '.self::incoming('memory_bytes_max').')'),
            'memory_limit_bytes' => new Expression(self::incoming('memory_limit_bytes')),
            'restart_count' => new Expression($greatest.'('.self::stored('restart_count').', '.self::incoming('restart_count').')'),
            'last_sampled_at' => new Expression(self::incoming('last_sampled_at')),
        ];
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function stored(string $column): string
    {
        return '"'.ProjectMetricMinute::TABLE.'"."'.$column.'"';
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function incoming(string $column): string
    {
        return '"excluded"."'.$column.'"';
    }
}
