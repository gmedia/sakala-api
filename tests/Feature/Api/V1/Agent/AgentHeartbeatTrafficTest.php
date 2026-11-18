<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function trafficEntry(string $projectId, string $deploymentId, string $lastRequestAt): array
{
    return [
        'project_id' => $projectId,
        'deployment_id' => $deploymentId,
        'last_request_at' => $lastRequestAt,
    ];
}

/** Payload whose traffic list is replaced outright, not merged. */
function trafficPayload(array $items, ?int $reportedCount = null): array
{
    $payload = heartbeatPayload(['capabilities' => ['docker-runtime', 'idle-sleep']]);
    $payload['metadata']['workloads']['traffic'] = $items;
    $payload['metadata']['detail_counts']['traffic'] = $reportedCount ?? count($items);

    return $payload;
}

test('a traffic report moves last_request_at of the served project forward', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $at = now()->subMinutes(3)->startOfSecond();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload([
            trafficEntry($project->id, $deployment->id, $at->toIso8601ZuluString()),
        ]))
        ->assertOk();

    expect($project->fresh()->last_request_at?->equalTo($at))->toBeTrue();
});

test('an older or equal report never moves last_request_at back', function (int $minutesAgo): void {
    $node = idleSleepNode();
    $stored = now()->subMinutes(2)->startOfSecond();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => $stored]);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload([
            trafficEntry($project->id, $deployment->id, now()->subMinutes($minutesAgo)->startOfSecond()->toIso8601ZuluString()),
        ]))
        ->assertOk();

    expect($project->fresh()->last_request_at?->equalTo($stored))->toBeTrue();
})->with(['older' => 10, 'equal' => 2]);

test('a time ahead of the API clock is stored as now', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload([
            trafficEntry($project->id, $deployment->id, now()->addHour()->toIso8601ZuluString()),
        ]))
        ->assertOk();

    expect($project->fresh()->last_request_at?->equalTo(now()->startOfSecond()))->toBeTrue();
});

test('only the node serving the active deployment speaks for a project', function (): void {
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
    [$mismatched, $mismatchedDeployment] = idleSleepProject($node);
    $at = now()->subMinute()->toIso8601ZuluString();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload([
            trafficEntry($served->id, $servedDeployment->id, $at),
            // Another node's workload, a superseded container, and a project
            // that does not own the deployment are all ignored.
            trafficEntry($elsewhere->id, $elsewhereDeployment->id, $at),
            trafficEntry($redeployed->id, $old->id, $at),
            trafficEntry($served->id, $mismatchedDeployment->id, $at),
        ]))
        ->assertOk();

    expect($served->fresh()->last_request_at)->not->toBeNull()
        ->and($elsewhere->fresh()->last_request_at)->toBeNull()
        ->and($redeployed->fresh()->last_request_at)->toBeNull()
        ->and($mismatched->fresh()->last_request_at)->toBeNull();
});

test('the newest of repeated items for one deployment wins', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $newest = now()->subMinute()->startOfSecond();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload([
            trafficEntry($project->id, $deployment->id, $newest->toIso8601ZuluString()),
            trafficEntry($project->id, $deployment->id, now()->subMinutes(9)->toIso8601ZuluString()),
        ]))
        ->assertOk();

    expect($project->fresh()->last_request_at?->equalTo($newest))->toBeTrue();
});

test('a heartbeat without traffic changes nothing', function (): void {
    $node = idleSleepNode();
    $stored = now()->subMinutes(40)->startOfSecond();
    [$project] = idleSleepProject($node, ['last_request_at' => $stored]);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload())
        ->assertOk();

    expect($project->fresh()->last_request_at?->equalTo($stored))->toBeTrue();
});

test('a full list costs one project read and at most one write', function (): void {
    $node = idleSleepNode();
    $items = [];
    $projects = [];

    foreach (range(1, 50) as $index) {
        [$project, $deployment] = idleSleepProject($node);
        $projects[] = $project;
        $items[] = trafficEntry($project->id, $deployment->id, now()->subSeconds($index)->toIso8601ZuluString());
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload($items))
        ->assertOk();

    $projectQueries = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"projects"')));
    $projectWrites = array_filter($projectQueries, static fn (string $sql): bool => str_starts_with($sql, 'update'));

    expect($projectQueries)->toHaveCount(2)
        ->and($projectWrites)->toHaveCount(1)
        ->and(Project::query()->whereNull('last_request_at')->count())->toBe(0)
        ->and($projects[4]->fresh()->last_request_at?->equalTo(now()->subSeconds(5)->startOfSecond()))->toBeTrue();

    // The same report again advances nothing, so nothing is written.
    $queries = [];

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload($items))
        ->assertOk();

    $projectQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"projects"'));
    $projectWrites = array_filter($projectQueries, static fn (string $sql): bool => str_starts_with($sql, 'update'));

    expect($projectQueries)->toHaveCount(1)
        ->and($projectWrites)->toBeEmpty();
});

test('the documented agent example is accepted', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $payload = trafficPayload([
        trafficEntry($project->id, $deployment->id, '2026-11-13T05:58:12Z'),
        trafficEntry('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '2026-11-13T05:12:40Z'),
    ]);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertOk();

    expect($project->fresh()->last_request_at?->toIso8601ZuluString())->toBe('2026-11-13T05:58:12Z')
        ->and($node->fresh()->metadata['workloads']['traffic'])->toHaveCount(2);
});

test('malformed traffic rejects the whole heartbeat', function (callable $mutate, string $errorKey): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $payload = trafficPayload([trafficEntry($project->id, $deployment->id, now()->toIso8601ZuluString())]);

    $mutate($payload);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect($project->fresh()->last_request_at)->toBeNull();
})->with([
    'not a list' => [function (array &$payload): void {
        $payload['metadata']['workloads']['traffic'] = 'busy';
    }, 'metadata.workloads.traffic'],
    'project id not a uuid' => [function (array &$payload): void {
        $payload['metadata']['workloads']['traffic'][0]['project_id'] = 'nope';
    }, 'metadata.workloads.traffic.0.project_id'],
    'missing deployment id' => [function (array &$payload): void {
        unset($payload['metadata']['workloads']['traffic'][0]['deployment_id']);
    }, 'metadata.workloads.traffic.0.deployment_id'],
    'null time' => [function (array &$payload): void {
        $payload['metadata']['workloads']['traffic'][0]['last_request_at'] = null;
    }, 'metadata.workloads.traffic.0.last_request_at'],
    'not a time' => [function (array &$payload): void {
        $payload['metadata']['workloads']['traffic'][0]['last_request_at'] = 'yesterday-ish';
    }, 'metadata.workloads.traffic.0.last_request_at'],
    'negative count' => [function (array &$payload): void {
        $payload['metadata']['detail_counts']['traffic'] = -1;
    }, 'metadata.detail_counts.traffic'],
]);

test('a list longer than the bound is refused', function (): void {
    $node = idleSleepNode();
    $item = trafficEntry('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '2026-11-13T05:12:40Z');

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', trafficPayload(array_fill(0, 501, $item)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.workloads.traffic']);
});
