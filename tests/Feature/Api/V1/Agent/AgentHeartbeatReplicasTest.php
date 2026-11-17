<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Http\Requests\Api\V1\Agent\AgentHeartbeatRequest;
use App\Models\AgentNode;
use App\Models\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function replicaEntry(string $projectId, string $deploymentId, int $desired, int $ready): array
{
    return [
        'project_id' => $projectId,
        'deployment_id' => $deploymentId,
        'desired' => $desired,
        'ready' => $ready,
    ];
}

/** Payload whose replica list is replaced outright, not merged. */
function replicasPayload(array $items, ?int $reportedCount = null): array
{
    $payload = heartbeatPayload(['capabilities' => ['docker-runtime', 'idle-sleep', AgentNode::REPLICAS_CAPABILITY]]);
    $payload['metadata']['workloads']['replicas'] = $items;
    $payload['metadata']['detail_counts']['replicas'] = $reportedCount ?? count($items);

    return $payload;
}

test('a replica report stores desired and ready for the serving deployment', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['replicas' => 3], ['replicas' => 3]);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', replicasPayload([replicaEntry($project->id, $deployment->id, 3, 1)]))
        ->assertOk();

    $deployment->refresh();

    // Some replicas down is not a crash: the project still serves.
    expect($deployment->replicas_desired)->toBe(3)
        ->and($deployment->replicas_ready)->toBe(1)
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($node->fresh()->hasCapability(AgentNode::REPLICAS_CAPABILITY))->toBeTrue();
});

test('only the serving deployment on the reporting node is stored', function (): void {
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
    [$asleep, $asleepDeployment] = idleSleepProject($node, ['runtime_status' => RuntimeStatus::Sleeping]);
    [, $mismatchedDeployment] = idleSleepProject($node);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', replicasPayload([
            replicaEntry($served->id, $servedDeployment->id, 2, 2),
            // Another node's workload, a superseded deployment, a sleeping
            // one, and a project that does not own the deployment.
            replicaEntry($elsewhere->id, $elsewhereDeployment->id, 2, 2),
            replicaEntry($redeployed->id, $old->id, 2, 2),
            replicaEntry($asleep->id, $asleepDeployment->id, 2, 0),
            replicaEntry($served->id, $mismatchedDeployment->id, 2, 2),
        ]))
        ->assertOk();

    expect($servedDeployment->fresh()->replicas_ready)->toBe(2)
        ->and($elsewhereDeployment->fresh()->replicas_ready)->toBeNull()
        ->and($old->fresh()->replicas_ready)->toBeNull()
        ->and($current->fresh()->replicas_ready)->toBeNull()
        ->and($asleepDeployment->fresh()->replicas_ready)->toBeNull()
        ->and($mismatchedDeployment->fresh()->replicas_ready)->toBeNull();
});

test('the last of repeated items for one deployment wins', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', replicasPayload([
            replicaEntry($project->id, $deployment->id, 3, 1),
            replicaEntry($project->id, $deployment->id, 3, 3),
        ]))
        ->assertOk();

    expect($deployment->fresh()->replicas_ready)->toBe(3);
});

test('a heartbeat without replicas keeps the last report', function (): void {
    $node = idleSleepNode();
    [, $deployment] = idleSleepProject($node, deploymentOverrides: ['replicas' => 2, 'replicas_desired' => 2, 'replicas_ready' => 2]);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload())
        ->assertOk();

    expect($deployment->fresh()->replicas_ready)->toBe(2);
});

test('a full list costs one deployment read and at most one write', function (): void {
    $node = idleSleepNode();
    $items = [];
    $deployments = [];

    foreach (range(1, 50) as $index) {
        [$project, $deployment] = idleSleepProject($node, ['replicas' => 3], ['replicas' => 3]);
        $deployments[] = $deployment;
        $items[] = replicaEntry($project->id, $deployment->id, 3, $index % 4);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', replicasPayload($items))
        ->assertOk();

    $deploymentQueries = array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "deployments"') || str_starts_with($sql, 'update "deployments"')));
    $deploymentWrites = array_filter($deploymentQueries, static fn (string $sql): bool => str_starts_with($sql, 'update'));

    expect($deploymentQueries)->toHaveCount(2)
        ->and($deploymentWrites)->toHaveCount(1)
        ->and(Deployment::query()->whereNull('replicas_ready')->count())->toBe(0)
        ->and($deployments[4]->fresh()->replicas_ready)->toBe(1)
        ->and($deployments[4]->fresh()->replicas_desired)->toBe(3);

    // The same report again changes nothing, so nothing is written.
    $queries = [];

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', replicasPayload($items))
        ->assertOk();

    $deploymentWrites = array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'update "deployments"'));

    expect($deploymentWrites)->toBeEmpty();
});

test('malformed replicas reject the whole heartbeat', function (callable $mutate, string $errorKey): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node);
    $payload = replicasPayload([replicaEntry($project->id, $deployment->id, 2, 1)]);

    $mutate($payload);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect($deployment->fresh()->replicas_ready)->toBeNull();
})->with([
    'not a list' => [function (array &$payload): void {
        $payload['metadata']['workloads']['replicas'] = 'many';
    }, 'metadata.workloads.replicas'],
    'project id not a uuid' => [function (array &$payload): void {
        $payload['metadata']['workloads']['replicas'][0]['project_id'] = 'nope';
    }, 'metadata.workloads.replicas.0.project_id'],
    'missing deployment id' => [function (array &$payload): void {
        unset($payload['metadata']['workloads']['replicas'][0]['deployment_id']);
    }, 'metadata.workloads.replicas.0.deployment_id'],
    'zero desired' => [function (array &$payload): void {
        $payload['metadata']['workloads']['replicas'][0]['desired'] = 0;
    }, 'metadata.workloads.replicas.0.desired'],
    'negative ready' => [function (array &$payload): void {
        $payload['metadata']['workloads']['replicas'][0]['ready'] = -1;
    }, 'metadata.workloads.replicas.0.ready'],
    'ready beyond the bound' => [function (array &$payload): void {
        $payload['metadata']['workloads']['replicas'][0]['ready'] = AgentHeartbeatRequest::MAX_REPORTED_REPLICAS + 1;
    }, 'metadata.workloads.replicas.0.ready'],
    'not a number' => [function (array &$payload): void {
        $payload['metadata']['workloads']['replicas'][0]['desired'] = 'three';
    }, 'metadata.workloads.replicas.0.desired'],
    'negative count' => [function (array &$payload): void {
        $payload['metadata']['detail_counts']['replicas'] = -1;
    }, 'metadata.detail_counts.replicas'],
]);

test('a replica list longer than the bound is refused', function (): void {
    $node = idleSleepNode();
    $item = replicaEntry('11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', 2, 2);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', replicasPayload(array_fill(0, AgentHeartbeatRequest::MAX_REPLICA_ITEMS + 1, $item)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.workloads.replicas']);
});
