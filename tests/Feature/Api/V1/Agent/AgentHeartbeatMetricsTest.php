<?php

declare(strict_types=1);

use App\Actions\Agent\RecordWorkloadMetricsAction;
use App\Enums\DeploymentStatus;
use App\Http\Requests\Api\V1\Agent\AgentHeartbeatRequest;
use App\Models\Deployment;
use App\Models\ProjectMetricMinute;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const METRICS_NOW = '2026-11-18 10:30:20';

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(METRICS_NOW, 'UTC'));
});

/** @param array<string, mixed> $overrides */
function metricSample(string $projectId, string $deploymentId, array $overrides = []): array
{
    return [
        'project_id' => $projectId,
        'deployment_id' => $deploymentId,
        'replica' => 0,
        'sampled_at' => now()->subSeconds(5)->toIso8601ZuluString(),
        'cpu_percent' => 12.5,
        'memory_bytes' => 100 * 1024 * 1024,
        'memory_limit_bytes' => 512 * 1024 * 1024,
        'restart_count' => 0,
        ...$overrides,
    ];
}

/** Payload whose metric list is replaced outright, not merged. */
function metricsPayload(array $items): array
{
    $payload = heartbeatPayload(['capabilities' => ['docker-runtime', 'workload-metrics']]);
    $payload['metadata']['workloads']['metrics'] = $items;
    $payload['metadata']['detail_counts']['metrics'] = count($items);

    return $payload;
}

function sendMetrics(mixed $test, $node, array $items): TestResponse
{
    return $test->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', metricsPayload($items));
}

test('a sample of the served deployment is stored as a minute row', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    sendMetrics($this, $node, [
        metricSample($project->id, $deployment->id, ['replica' => 1, 'cpu_percent' => 150.5, 'restart_count' => 2]),
    ])->assertOk();

    $row = ProjectMetricMinute::query()->sole();

    expect($row->project_id)->toBe($project->id)
        ->and($row->deployment_id)->toBe($deployment->id)
        ->and($row->replica)->toBe(1)
        ->and($row->bucket->toDateTimeString())->toBe('2026-11-18 10:30:00')
        ->and($row->sample_count)->toBe(1)
        // A percent of one core: more than 100 on a busy multi-core container.
        ->and($row->cpu_percent_sum)->toBe(150.5)
        ->and($row->cpu_percent_max)->toBe(150.5)
        ->and($row->memory_bytes_sum)->toBe(100 * 1024 * 1024)
        ->and($row->memory_limit_bytes)->toBe(512 * 1024 * 1024)
        ->and($row->restart_count)->toBe(2)
        ->and($row->last_sampled_at->toDateTimeString())->toBe('2026-11-18 10:30:15');
});

test('samples of one minute aggregate within a heartbeat and across heartbeats', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $minute = CarbonImmutable::parse('2026-11-18 10:29:00', 'UTC');

    sendMetrics($this, $node, [
        metricSample($project->id, $deployment->id, ['sampled_at' => $minute->addSeconds(10)->toIso8601ZuluString(), 'cpu_percent' => 10, 'memory_bytes' => 100, 'restart_count' => 1]),
        metricSample($project->id, $deployment->id, ['sampled_at' => $minute->addSeconds(20)->toIso8601ZuluString(), 'cpu_percent' => 30, 'memory_bytes' => 300, 'memory_limit_bytes' => 1000, 'restart_count' => 1]),
        // Another minute and another replica get their own rows.
        metricSample($project->id, $deployment->id, ['sampled_at' => $minute->addSeconds(70)->toIso8601ZuluString()]),
        metricSample($project->id, $deployment->id, ['replica' => 1, 'sampled_at' => $minute->addSeconds(20)->toIso8601ZuluString()]),
    ])->assertOk();

    sendMetrics($this, $node, [
        metricSample($project->id, $deployment->id, ['sampled_at' => $minute->addSeconds(30)->toIso8601ZuluString(), 'cpu_percent' => 20, 'memory_bytes' => 200, 'memory_limit_bytes' => null, 'restart_count' => 3]),
    ])->assertOk();

    expect(ProjectMetricMinute::query()->count())->toBe(3);

    $row = ProjectMetricMinute::query()->where('replica', 0)->where('bucket', $minute)->sole();

    expect($row->sample_count)->toBe(3)
        ->and($row->cpu_percent_sum / $row->sample_count)->toBe(20.0)
        ->and($row->cpu_percent_max)->toBe(30.0)
        ->and(intdiv($row->memory_bytes_sum, $row->sample_count))->toBe(200)
        ->and($row->memory_bytes_max)->toBe(300)
        // The newest sample sets the limit, even to "no limit".
        ->and($row->memory_limit_bytes)->toBeNull()
        ->and($row->restart_count)->toBe(3)
        ->and($row->last_sampled_at->toDateTimeString())->toBe('2026-11-18 10:29:30');
});

test('a sample sent again is not counted twice', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $sample = metricSample($project->id, $deployment->id, ['sampled_at' => '2026-11-18T10:30:05.250Z', 'cpu_percent' => 40]);

    sendMetrics($this, $node, [$sample])->assertOk();
    sendMetrics($this, $node, [$sample])->assertOk();
    // Within one heartbeat too, and an older sample of the same minute.
    sendMetrics($this, $node, [$sample, $sample, metricSample($project->id, $deployment->id, ['sampled_at' => '2026-11-18T10:30:01Z'])])->assertOk();

    $row = ProjectMetricMinute::query()->sole();

    expect($row->sample_count)->toBe(1)
        ->and($row->cpu_percent_sum)->toBe(40.0);
});

test('samples for anything the node does not serve are ignored without failing the heartbeat', function (): void {
    $node = idleSleepNode();
    $other = idleSleepNode(['token_hash' => hash_hmac('sha256', 'other-token', (string) config('app.key'))]);
    [$served, $servedDeployment] = idleSleepProject($node);
    [$elsewhere, $elsewhereDeployment] = idleSleepProject($other);
    [$redeployed, $old] = idleSleepProject($node);
    $current = Deployment::factory()->for($redeployed)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 2,
        'finished_at' => now()->subMinute(),
    ]);
    $redeployed->update(['active_deployment_id' => $current->id]);
    [, $mismatchedDeployment] = idleSleepProject($node);

    sendMetrics($this, $node, [
        metricSample($served->id, $servedDeployment->id),
        // Another node's workload, a superseded container, a project that
        // does not own the deployment, and IDs the API never issued.
        metricSample($elsewhere->id, $elsewhereDeployment->id),
        metricSample($redeployed->id, $old->id),
        metricSample($served->id, $mismatchedDeployment->id),
        metricSample('0193a7a0-0000-7000-8000-000000000001', '0193a7a0-0000-7000-8000-000000000002'),
    ])->assertOk();

    expect(ProjectMetricMinute::query()->pluck('deployment_id')->all())->toBe([$servedDeployment->id]);
});

test('stale samples are dropped and samples ahead of the API clock count as now', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    sendMetrics($this, $node, [
        metricSample($project->id, $deployment->id, ['sampled_at' => now()->subSeconds(RecordWorkloadMetricsAction::MAX_SAMPLE_AGE_SECONDS + 1)->toIso8601ZuluString()]),
        metricSample($project->id, $deployment->id, ['replica' => 1, 'sampled_at' => now()->addHour()->toIso8601ZuluString()]),
    ])->assertOk();

    $row = ProjectMetricMinute::query()->sole();

    expect($row->replica)->toBe(1)
        ->and($row->bucket->toDateTimeString())->toBe('2026-11-18 10:30:00')
        ->and($row->last_sampled_at->toDateTimeString())->toBe(METRICS_NOW);
});

test('malformed samples reject the heartbeat like the other lists', function (array $override): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $sample = metricSample($project->id, $deployment->id, $override);

    if (array_key_exists('missing', $override)) {
        unset($sample[$override['missing']], $sample['missing']);
    }

    sendMetrics($this, $node, [$sample])->assertUnprocessable();

    expect(ProjectMetricMinute::query()->count())->toBe(0);
})->with([
    'negative cpu' => [['cpu_percent' => -1]],
    'cpu beyond the bound' => [['cpu_percent' => AgentHeartbeatRequest::MAX_REPORTED_CPU_PERCENT + 1]],
    'negative replica' => [['replica' => -1]],
    'fractional memory' => [['memory_bytes' => 1.5]],
    'negative restarts' => [['restart_count' => -1]],
    'not a time' => [['sampled_at' => 'yesterday-ish']],
    'not a uuid' => [['deployment_id' => 'web-1']],
    'limit missing' => [['missing' => 'memory_limit_bytes']],
    'cpu missing' => [['missing' => 'cpu_percent']],
]);

test('the list is bounded', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    sendMetrics($this, $node, array_fill(0, AgentHeartbeatRequest::MAX_METRIC_ITEMS + 1, metricSample($project->id, $deployment->id)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('metadata.workloads.metrics');
});

test('samples are not kept in the node metadata', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    sendMetrics($this, $node, [metricSample($project->id, $deployment->id)])->assertOk();

    $metadata = $node->fresh()->metadata;

    expect($metadata['workloads'])->not->toHaveKey('metrics')
        ->and($metadata['detail_counts']['metrics'])->toBe(1);
});

test('a heartbeat without metrics writes no metric rows', function (): void {
    $node = idleSleepNode();
    idleSleepProject($node);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload())
        ->assertOk();

    expect(ProjectMetricMinute::query()->count())->toBe(0);
});

test('a full list costs one project read, one minute read, and one upsert', function (): void {
    $node = idleSleepNode();
    $items = [];

    foreach (range(1, 20) as $index) {
        [$project, $deployment] = idleSleepProject($node);
        $items[] = metricSample($project->id, $deployment->id);
        $items[] = metricSample($project->id, $deployment->id, ['replica' => 1]);
    }

    $metricQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$metricQueries): void {
        if (str_contains($query->sql, ProjectMetricMinute::TABLE)) {
            $metricQueries++;
        }
    });

    sendMetrics($this, $node, $items)->assertOk();

    expect($metricQueries)->toBe(2)
        ->and(ProjectMetricMinute::query()->count())->toBe(40);
});
