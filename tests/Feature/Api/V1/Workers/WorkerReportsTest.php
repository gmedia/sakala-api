<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\ProjectMetricMinute;
use App\Notifications\UserAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableWorkersAndSchedules();
});

/** @return array{project: Project, node: AgentNode, live: Deployment, command: AgentCommand, team: array<string, mixed>} */
function workerLive(): array
{
    ['team' => $team, 'project' => $project, 'node' => $node] = workerProject();
    addProcess($project);
    $live = deployLive($project, $team['owner']);
    $command = AgentCommand::query()->where('deployment_id', $live->id)->where('type', AgentCommandType::DeployProject)->sole();
    $command->update(['status' => AgentCommandStatus::Running, 'agent_node_id' => $node->id]);

    return ['project' => $project->fresh(), 'node' => $node, 'live' => $live, 'command' => $command->fresh(), 'team' => $team];
}

/** The agent's worker-processes heartbeat example, for `$live`, merged into a full heartbeat. */
function workerHeartbeat(Project $project, Deployment $live, ?callable $edit = null): array
{
    $fixture = agentHeartbeatFixture('worker-processes');
    $json = str_replace(
        ['ff66ed4a-6303-4be6-8ef4-63c28b112680', '4f1f21ef-730d-42d5-a46d-d965353cb993'],
        [$project->id, $live->id],
        json_encode($fixture, JSON_THROW_ON_ERROR),
    );
    $fixture = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    $payload = heartbeatPayload();
    $payload['capabilities'] = $fixture['capabilities'];
    $payload['metadata']['workloads'] = [...$payload['metadata']['workloads'], ...$fixture['metadata']['workloads']];
    $payload['metadata']['detail_counts'] = [...$payload['metadata']['detail_counts'], ...$fixture['metadata']['detail_counts']];

    foreach ($payload['metadata']['workloads']['metrics'] as $index => $metric) {
        $payload['metadata']['workloads']['metrics'][$index]['sampled_at'] = now()->subSeconds(5)->toIso8601ZuluString();
    }

    return $edit === null ? $payload : $edit($payload);
}

test('worker log lines keep their process and their own byte budget', function (): void {
    ['node' => $node, 'live' => $live, 'command' => $command] = workerLive();
    config(['sakala.pilot_limits.log_bounds.max_total_bytes' => 25]);

    $line = static fn (string $message, ?string $process = null): array => [
        'stream' => 'stdout',
        'message' => $message,
        'recorded_at' => now()->toIso8601ZuluString(),
        ...($process === null ? [] : ['process' => $process]),
    ];

    $this->withHeaders(workerNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [$line('[runtime] web up 12345')]])
        ->assertOk();

    // The web budget (25 bytes) is nearly spent; the worker still has its own.
    $this->withHeaders(workerNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [$line('Processing job 1', 'queue')]])
        ->assertOk();
    $this->withHeaders(workerNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [$line('Processing job 2', 'queue')]])
        ->assertUnprocessable();

    expect(DeploymentLog::query()->where('agent_command_id', $command->id)->orderBy('sequence')->pluck('process')->all())->toBe([null, 'queue'])
        ->and($command->fresh()->process_log_bytes)->toBe(['queue' => 16]);
});

test('the existing log endpoints filter by process', function (): void {
    ['project' => $project, 'node' => $node, 'live' => $live, 'command' => $command, 'team' => $team] = workerLive();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [
        ['stream' => 'system', 'message' => '[release] migrated', 'recorded_at' => now()->toIso8601ZuluString()],
        ['stream' => 'stdout', 'message' => 'job done', 'recorded_at' => now()->toIso8601ZuluString(), 'process' => 'queue'],
    ]])->assertOk();

    $url = "/api/v1/app/projects/{$project->id}/deployments/{$live->id}/logs";

    $all = $this->actingAs($team['viewer'])->getJson($url.'?per_page=50')->assertOk()->json('data');
    $web = $this->actingAs($team['viewer'])->getJson($url.'?process=web&per_page=50')->assertOk()->json('data');

    $this->actingAs($team['viewer'])->getJson($url.'?process=queue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'job done')->assertJsonPath('data.0.process', 'queue');

    expect(count($web))->toBe(count($all) - 1)
        ->and(array_unique(array_column($web, 'process')))->toBe([null]);
    $this->actingAs($team['viewer'])->getJson($url.'?process=Bad%20Name')->assertUnprocessable();
});

test('a log line without process is stored exactly as before', function (): void {
    ['node' => $node, 'command' => $command] = workerLive();

    $this->withHeaders(workerNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", agentReportFixture('logs-batch'))
        ->assertOk();

    expect(DeploymentLog::query()->whereNotNull('process')->count())->toBe(0)
        ->and($command->fresh()->process_log_bytes)->toBeNull();
});

test('the agent worker heartbeat example records the crashing worker without marking the web process crashed', function (): void {
    ['project' => $project, 'node' => $node, 'live' => $live] = workerLive();
    $project->update(['runtime_status' => RuntimeStatus::Running]);
    Notification::fake();

    $this->withHeaders(workerNodeHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', workerHeartbeat($project, $live))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($live->fresh()->crashed_processes)->toHaveKey('queue')
        ->and($live->fresh()->crashed_processes['queue'])->toMatchArray(['exit_code' => 1, 'restart_count' => 4, 'running' => false]);

    // Web samples only: the worker's replica 0 does not merge into the web one.
    expect(ProjectMetricMinute::query()->where('deployment_id', $live->id)->sole())
        ->sample_count->toBe(1)
        ->memory_bytes_max->toBe(104857600);

    Notification::assertSentTimes(UserAlertNotification::class, 2);

    // The same report again changes and notifies nothing.
    $this->withHeaders(workerNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', workerHeartbeat($project, $live))->assertOk();
    Notification::assertSentTimes(UserAlertNotification::class, 2);

    // Gone from a complete list: recovered.
    $this->withHeaders(workerNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', workerHeartbeat($project, $live, function (array $payload): array {
        $payload['metadata']['workloads']['crashed_details'] = [];
        $payload['metadata']['detail_counts']['crashed_details'] = 0;

        return $payload;
    }))->assertOk();

    expect($live->fresh()->crashed_processes)->toBeNull();
});

test('a web crash next to a worker crash still marks the project crashed', function (): void {
    ['project' => $project, 'node' => $node, 'live' => $live] = workerLive();
    $project->update(['runtime_status' => RuntimeStatus::Running]);

    $this->withHeaders(workerNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', workerHeartbeat($project, $live, function (array $payload) use ($project, $live): array {
        $payload['metadata']['workloads']['crashed_details'][] = [
            'project_id' => $project->id, 'deployment_id' => $live->id, 'exit_code' => 137, 'oom_killed' => true,
            'finished_at' => '2026-11-24T09:14:58Z', 'restart_count' => 3, 'running' => false,
        ];
        $payload['metadata']['detail_counts']['crashed_details'] = 2;

        return $payload;
    }))->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and($live->fresh()->crashed_processes)->toHaveKey('queue');
});

test('a malformed process key rejects the heartbeat', function (): void {
    ['project' => $project, 'node' => $node, 'live' => $live] = workerLive();

    $this->withHeaders(workerNodeHeaders($node))->postJson('/api/agent/v1/heartbeat', workerHeartbeat($project, $live, function (array $payload): array {
        $payload['metadata']['workloads']['crashed_details'][0]['process'] = 'Not A Name';

        return $payload;
    }))->assertUnprocessable();
});
