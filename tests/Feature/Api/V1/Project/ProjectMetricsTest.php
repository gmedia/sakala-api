<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectMetricsRange;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:30:20', 'UTC'));
});

/** @return array{User, Project, Deployment} */
function metricsProject(): array
{
    $owner = User::factory()->create();
    [$project, $deployment] = idleSleepProject(AgentNode::query()->first() ?? idleSleepNode(), owner: $owner);

    return [$owner, $project, $deployment];
}

function metricsUrl(Project $project, string $query = ''): string
{
    return "/api/v1/app/projects/{$project->id}/metrics".($query === '' ? '' : '?'.$query);
}

function iso(string $time): string
{
    return CarbonImmutable::parse($time, 'UTC')->toIso8601String();
}

test('a project without data answers empty series', function (): void {
    [$owner, $project] = metricsProject();

    $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project))
        ->assertOk()
        ->assertExactJson(['data' => [
            'range' => '1h',
            'step_seconds' => 60,
            'series' => [
                'cpu_percent' => [],
                'memory_bytes' => [],
                'memory_limit_bytes' => null,
                'replicas' => [],
                'restarts' => [],
            ],
        ]]);
});

test('1h reads minute rows, sums replicas, and leaves gaps', function (): void {
    [$owner, $project, $deployment] = metricsProject();

    // Before the range: only the restart baseline.
    metricMinuteRow($deployment, '2026-11-18 09:25:00', ['restart_count' => 1]);
    metricMinuteRow($deployment, '2026-11-18 09:31:00', ['restart_count' => 2]);
    metricMinuteRow($deployment, '2026-11-18 10:28:00', ['restart_count' => 2, 'sample_count' => 2, 'cpu_percent_sum' => 30.0, 'cpu_percent_max' => 20.0, 'memory_bytes_sum' => 3000, 'memory_bytes_max' => 2000]);
    metricMinuteRow($deployment, '2026-11-18 10:28:00', ['replica' => 1, 'cpu_percent_sum' => 120.0, 'cpu_percent_max' => 120.0]);
    // 10:29 has no row: a gap, not a zero.
    metricMinuteRow($deployment, '2026-11-18 10:30:00', ['restart_count' => 3, 'memory_limit_bytes' => 5000]);
    metricMinuteRow($deployment, '2026-11-18 10:30:00', ['replica' => 1]);
    // Hourly rows and other projects are not read.
    metricHourRow($deployment, '2026-11-18 10:00:00', ['cpu_percent_max' => 999.0]);
    [, , $otherDeployment] = metricsProject();
    metricMinuteRow($otherDeployment, '2026-11-18 10:29:00');

    $series = $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'range=1h'))
        ->assertOk()
        ->assertJsonPath('data.range', '1h')
        ->assertJsonPath('data.step_seconds', 60)
        ->assertJsonMissingPath('data.series.per_replica')
        ->json('data.series');

    expect($series['cpu_percent'])->toBe([
        ['t' => iso('2026-11-18 09:31:00'), 'avg' => 10, 'max' => 10],
        // Replica 0 averages 15 over its two samples; replica 1 adds 120.
        ['t' => iso('2026-11-18 10:28:00'), 'avg' => 135, 'max' => 140],
        ['t' => iso('2026-11-18 10:30:00'), 'avg' => 20, 'max' => 20],
    ])
        ->and($series['memory_bytes'][1])->toBe(['t' => iso('2026-11-18 10:28:00'), 'avg' => 2500, 'max' => 3000])
        ->and($series['memory_limit_bytes'])->toBe(9000)
        ->and($series['replicas'])->toBe([
            ['t' => iso('2026-11-18 09:31:00'), 'count' => 1],
            ['t' => iso('2026-11-18 10:28:00'), 'count' => 2],
            ['t' => iso('2026-11-18 10:30:00'), 'count' => 2],
        ])
        ->and($series['restarts'])->toBe([
            ['t' => iso('2026-11-18 09:31:00'), 'count' => 1],
            ['t' => iso('2026-11-18 10:28:00'), 'count' => 0],
            ['t' => iso('2026-11-18 10:30:00'), 'count' => 1],
        ]);
});

test('the memory limit is null when a replica runs without one', function (): void {
    [$owner, $project, $deployment] = metricsProject();
    metricMinuteRow($deployment, '2026-11-18 10:30:00');
    metricMinuteRow($deployment, '2026-11-18 10:30:00', ['replica' => 1, 'memory_limit_bytes' => null]);

    $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project))
        ->assertJsonPath('data.series.memory_limit_bytes', null);
});

test('6h folds minute rows into five-minute steps', function (): void {
    [$owner, $project, $deployment] = metricsProject();
    metricMinuteRow($deployment, '2026-11-18 10:25:00', ['cpu_percent_sum' => 10.0, 'cpu_percent_max' => 10.0]);
    metricMinuteRow($deployment, '2026-11-18 10:26:00', ['cpu_percent_sum' => 20.0, 'cpu_percent_max' => 25.0, 'restart_count' => 2]);
    metricMinuteRow($deployment, '2026-11-18 10:29:00', ['cpu_percent_sum' => 30.0, 'cpu_percent_max' => 30.0, 'restart_count' => 3]);
    metricMinuteRow($deployment, '2026-11-18 10:30:00', ['cpu_percent_sum' => 5.0, 'cpu_percent_max' => 5.0, 'restart_count' => 3]);

    $series = $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'range=6h'))
        ->assertOk()
        ->assertJsonPath('data.step_seconds', 300)
        ->json('data.series');

    expect($series['cpu_percent'])->toBe([
        ['t' => iso('2026-11-18 10:25:00'), 'avg' => 20, 'max' => 30],
        ['t' => iso('2026-11-18 10:30:00'), 'avg' => 5, 'max' => 5],
    ])
        ->and($series['restarts'])->toBe([
            ['t' => iso('2026-11-18 10:25:00'), 'count' => 3],
            ['t' => iso('2026-11-18 10:30:00'), 'count' => 0],
        ]);
});

test('7d and 30d read hourly rows', function (string $range, int $step, string $firstT): void {
    [$owner, $project, $deployment] = metricsProject();
    metricHourRow($deployment, '2026-11-18 08:00:00', ['restarts' => 2, 'cpu_percent_sum' => 600.0, 'cpu_percent_max' => 40.0]);
    metricHourRow($deployment, '2026-11-18 09:00:00', ['restarts' => 1, 'cpu_percent_sum' => 1200.0, 'cpu_percent_max' => 30.0]);
    metricHourRow($deployment, '2026-11-18 09:00:00', ['replica' => 1]);
    // Minute rows are not read for these ranges.
    metricMinuteRow($deployment, '2026-11-18 10:29:00', ['cpu_percent_max' => 999.0]);

    $series = $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, "range={$range}"))
        ->assertOk()
        ->assertJsonPath('data.step_seconds', $step)
        ->json('data.series');

    expect($series['cpu_percent'][0]['t'])->toBe(iso($firstT))
        ->and(collect($series['cpu_percent'])->max('max'))->toEqual(40)
        ->and(collect($series['restarts'])->sum('count'))->toBe(3)
        ->and(collect($series['replicas'])->max('count'))->toBe(2);
})->with([
    '7d' => ['7d', 3600, '2026-11-18 08:00:00'],
    // Four-hour steps: 08:00 and 09:00 fall into the same point.
    '30d' => ['30d', 14400, '2026-11-18 08:00:00'],
]);

test('30d averages the hourly totals within a step', function (): void {
    [$owner, $project, $deployment] = metricsProject();
    metricHourRow($deployment, '2026-11-18 08:00:00', ['cpu_percent_sum' => 600.0, 'memory_bytes_sum' => 60000]);
    metricHourRow($deployment, '2026-11-18 09:00:00', ['cpu_percent_sum' => 1800.0, 'memory_bytes_sum' => 180000, 'memory_bytes_max' => 5000]);

    $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'range=30d'))
        ->assertJsonPath('data.series.cpu_percent', [['t' => iso('2026-11-18 08:00:00'), 'avg' => 20, 'max' => 10]])
        ->assertJsonPath('data.series.memory_bytes', [['t' => iso('2026-11-18 08:00:00'), 'avg' => 2000, 'max' => 5000]]);
});

test('every range has its step and window', function (ProjectMetricsRange $range, int $step, string $oldestKept, string $tooOld): void {
    [$owner, $project, $deployment] = metricsProject();
    $row = $range->readsHourlyRollups() ? metricHourRow(...) : metricMinuteRow(...);
    $row($deployment, $tooOld);
    $row($deployment, $oldestKept);

    $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'range='.$range->value))
        ->assertOk()
        ->assertJsonPath('data.range', $range->value)
        ->assertJsonPath('data.step_seconds', $step)
        ->assertJsonCount(1, 'data.series.cpu_percent')
        ->assertJsonPath('data.series.cpu_percent.0.t', iso($oldestKept));
})->with([
    '1h' => [ProjectMetricsRange::LastHour, 60, '2026-11-18 09:31:00', '2026-11-18 09:30:00'],
    '6h' => [ProjectMetricsRange::Last6Hours, 300, '2026-11-18 04:35:00', '2026-11-18 04:34:00'],
    '24h' => [ProjectMetricsRange::Last24Hours, 900, '2026-11-17 10:45:00', '2026-11-17 10:44:00'],
    '7d' => [ProjectMetricsRange::Last7Days, 3600, '2026-11-11 11:00:00', '2026-11-11 10:00:00'],
    '30d' => [ProjectMetricsRange::Last30Days, 14400, '2026-10-19 12:00:00', '2026-10-19 11:00:00'],
]);

test('per_replica adds a series for each replica', function (): void {
    [$owner, $project, $deployment] = metricsProject();
    metricMinuteRow($deployment, '2026-11-18 10:29:00', ['replica' => 1, 'cpu_percent_sum' => 50.0, 'cpu_percent_max' => 60.0, 'restart_count' => 1]);
    metricMinuteRow($deployment, '2026-11-18 10:30:00', ['replica' => 1, 'restart_count' => 4]);
    metricMinuteRow($deployment, '2026-11-18 10:30:00');

    $perReplica = $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'per_replica=1'))
        ->assertOk()
        ->json('data.series.per_replica');

    expect($perReplica)->toHaveCount(2)
        ->and($perReplica[0]['replica'])->toBe(0)
        ->and($perReplica[0]['cpu_percent'])->toBe([['t' => iso('2026-11-18 10:30:00'), 'avg' => 10, 'max' => 10]])
        ->and($perReplica[1]['replica'])->toBe(1)
        ->and($perReplica[1]['cpu_percent'][0])->toBe(['t' => iso('2026-11-18 10:29:00'), 'avg' => 50, 'max' => 60])
        ->and($perReplica[1]['restarts'][1])->toBe(['t' => iso('2026-11-18 10:30:00'), 'count' => 3])
        ->and(array_keys($perReplica[1]))->toBe(['replica', 'cpu_percent', 'memory_bytes', 'restarts']);
});

// Ledger X-32, seen on staging 2026-11-23: a restart finished at 07:09:56,
// the containers were first sampled at 07:11, and the 1h range lost the
// restart while 24h showed both.
test('a restart in a minute without samples still counts, while CPU and memory keep their gap', function (): void {
    [$owner, $project, $deployment] = metricsProject();
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $deployment->agent_node_id,
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => CarbonImmutable::parse('2026-11-18 10:09:56', 'UTC'),
    ]);
    metricMinuteRow($deployment, '2026-11-18 10:11:00');
    metricMinuteRow($deployment, '2026-11-18 10:19:00');
    // The second restart: a crash the counter shows.
    metricMinuteRow($deployment, '2026-11-18 10:20:00', ['restart_count' => 1]);

    $series = $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'range=1h&per_replica=1'))
        ->assertOk()
        ->json('data.series');

    $restarts = collect($series['restarts'])->pluck('count', 't');
    expect($restarts->all())->toBe([
        iso('2026-11-18 10:09:00') => $deployment->replicas,
        iso('2026-11-18 10:11:00') => 0,
        iso('2026-11-18 10:19:00') => 0,
        iso('2026-11-18 10:20:00') => 1,
    ])
        ->and($restarts->sum())->toBe($deployment->replicas + 1)
        ->and(array_column($series['cpu_percent'], 't'))->toBe([iso('2026-11-18 10:11:00'), iso('2026-11-18 10:19:00'), iso('2026-11-18 10:20:00')])
        ->and(array_column($series['memory_bytes'], 't'))->toBe(array_column($series['cpu_percent'], 't'))
        ->and(array_column($series['replicas'], 't'))->toBe(array_column($series['cpu_percent'], 't'))
        ->and($series['per_replica'][0]['restarts'][0])->toBe(['t' => iso('2026-11-18 10:09:00'), 'count' => 1]);

    // The 24h range agrees on the total.
    $daily = $this->actingAs($owner, 'web')->getJson(metricsUrl($project, 'range=24h'))->assertOk();
    expect(collect($daily->json('data.series.restarts'))->sum('count'))->toBe($deployment->replicas + 1);
});

test('a replica restarted in the range without any row gets a restarts series only', function (): void {
    [$owner, $project, $deployment] = metricsProject();
    $deployment->update(['replicas' => 2]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $deployment->agent_node_id,
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => CarbonImmutable::parse('2026-11-18 10:25:10', 'UTC'),
    ]);
    metricMinuteRow($deployment, '2026-11-18 10:29:00');

    $perReplica = $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, 'per_replica=1'))
        ->assertOk()
        ->json('data.series.per_replica');

    expect(array_column($perReplica, 'replica'))->toBe([0, 1])
        ->and($perReplica[1]['cpu_percent'])->toBe([])
        ->and($perReplica[1]['memory_bytes'])->toBe([])
        ->and($perReplica[1]['restarts'])->toBe([['t' => iso('2026-11-18 10:25:00'), 'count' => 1]]);
});

test('an unknown range or flag is rejected', function (string $query, string $field): void {
    [$owner, $project] = metricsProject();

    $this->actingAs($owner, 'web')
        ->getJson(metricsUrl($project, $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'range' => ['range=2h', 'range'],
    'per_replica' => ['per_replica=maybe', 'per_replica'],
]);

test('anyone who can view the project can read its metrics', function (WorkspaceRole $role): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    [$project, $deployment] = idleSleepProject(idleSleepNode(), ['workspace_id' => $team->id], owner: $owner);
    metricMinuteRow($deployment, '2026-11-18 10:30:00');
    $member = User::factory()->create();
    addWorkspaceMember($team, $member, $role);

    $this->actingAs($member, 'web')
        ->getJson(metricsUrl($project))
        ->assertOk()
        ->assertJsonCount(1, 'data.series.cpu_percent');
})->with([
    'member' => [WorkspaceRole::Member],
    'viewer' => [WorkspaceRole::Viewer],
]);

test('admins can read any project metrics', function (): void {
    [, $project] = metricsProject();

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson(metricsUrl($project))
        ->assertOk();
});

test('outsiders and guests cannot read metrics', function (): void {
    [, $project, $deployment] = metricsProject();
    metricMinuteRow($deployment, '2026-11-18 10:30:00');

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson(metricsUrl($project))
        ->assertForbidden();

    auth()->forgetGuards();

    $this->getJson(metricsUrl($project))->assertUnauthorized();
});

test('a member of another workspace cannot read metrics', function (): void {
    [, $project] = metricsProject();
    $stranger = User::factory()->create();
    teamWorkspace(User::factory()->create(), [[$stranger, WorkspaceRole::Owner]]);

    $this->actingAs($stranger, 'web')
        ->getJson(metricsUrl($project))
        ->assertForbidden();
});
