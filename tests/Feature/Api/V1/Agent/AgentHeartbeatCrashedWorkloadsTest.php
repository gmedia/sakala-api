<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Events\Deployment\DeploymentUpdated;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function crashNode(string $token = 'crash-token'): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

function crashHeaders(AgentNode $node, string $token = 'crash-token'): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $node->agent_id,
    ];
}

/** @return array{Project, Deployment} */
function liveWorkload(AgentNode $node, array $projectOverrides = []): array
{
    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
        ...$projectOverrides,
    ]);

    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);

    $project->update(['active_deployment_id' => $deployment->id]);

    return [$project->fresh(), $deployment];
}

function crashEntry(string $projectId, string $deploymentId, array $overrides = []): array
{
    return [
        'project_id' => $projectId,
        'deployment_id' => $deploymentId,
        'exit_code' => 137,
        'oom_killed' => true,
        'finished_at' => '2026-11-05T08:00:00Z',
        ...$overrides,
    ];
}

/** Payload whose crash list is replaced outright, not merged. */
function crashPayload(array $details, ?int $reportedCount = null): array
{
    $payload = heartbeatPayload();
    $payload['metadata']['workloads']['crashed_details'] = $details;
    $payload['metadata']['detail_counts']['crashed_details'] = $reportedCount ?? count($details);

    return $payload;
}

test('a crash of the active deployment marks the project crashed and broadcasts it', function (): void {
    Event::fake([DeploymentUpdated::class]);
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and($project->fresh()->active_deployment_id)->toBe($deployment->id);

    Event::assertDispatched(
        DeploymentUpdated::class,
        fn (DeploymentUpdated $event): bool => $event->broadcastWith()['deployment_id'] === $deployment->id
            && $event->broadcastWith()['project_runtime_status'] === 'crashed',
    );
});

test('repeating the same crash report changes nothing and broadcasts nothing', function (): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);
    $payload = crashPayload([crashEntry($project->id, $deployment->id)]);

    $this->withHeaders(crashHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();
    $sequence = (int) $deployment->fresh()->realtime_sequence;

    Event::fake([DeploymentUpdated::class]);
    $this->withHeaders(crashHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and((int) $deployment->fresh()->realtime_sequence)->toBe($sequence);
    Event::assertNotDispatched(DeploymentUpdated::class);
});

test('a heartbeat does not touch the status while a newer deployment is in progress', function (DeploymentStatus $attempt): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Deploying]);
    Deployment::factory()->for($project)->create(['agent_node_id' => $node->id, 'status' => $attempt, 'sequence' => 2]);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Deploying)
        ->and($project->user->notifications()->count())->toBe(0);
})->with([DeploymentStatus::Queued, DeploymentStatus::Building]);

test('a recovery waits for the attempt too', function (): void {
    $node = crashNode();
    [$project] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
    Deployment::factory()->for($project)->create(['agent_node_id' => $node->id, 'status' => DeploymentStatus::Queued, 'sequence' => 2]);

    $this->withHeaders(crashHeaders($node))->postJson('/api/agent/v1/heartbeat', crashPayload([]))->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed);
});

test('a crash loop surviving a failed redeploy is not announced twice', function (): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);
    $crash = crashPayload([crashEntry($project->id, $deployment->id)]);

    $this->withHeaders(crashHeaders($node))->postJson('/api/agent/v1/heartbeat', $crash)->assertOk();

    expect($project->user->notifications()->count())->toBe(1);

    $attempt = Deployment::factory()->for($project)->create(['agent_node_id' => $node->id, 'status' => DeploymentStatus::Building, 'sequence' => 2]);
    $project->update(['runtime_status' => RuntimeStatus::Deploying]);

    $this->withHeaders(crashHeaders($node))->postJson('/api/agent/v1/heartbeat', $crash)->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Deploying);

    $attempt->update(['status' => DeploymentStatus::Failed]);
    $project->update(['runtime_status' => RuntimeStatus::Running]);

    $this->withHeaders(crashHeaders($node))->postJson('/api/agent/v1/heartbeat', $crash)->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and($project->user->notifications()->count())->toBe(1);
});

test('the crash notification says access may be intermittent', function (): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id)]))
        ->assertOk();

    expect($project->user->notifications()->sole()->data['message'])
        ->toContain('terputus-putus')
        ->not->toContain('tidak melayani');
});

test('a crashed project no longer listed goes back to running', function (): void {
    $node = crashNode();
    [$project] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('a heartbeat without the field leaves runtime state untouched', function (): void {
    $node = crashNode();
    [$crashed] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
    [$running] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload())
        ->assertOk();

    expect($crashed->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and($running->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('a truncated list cannot prove recovery, so crashed projects stay crashed', function (): void {
    $node = crashNode();
    [$crashed] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
    [$other, $otherDeployment] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload(
            [crashEntry($other->id, $otherDeployment->id)],
            reportedCount: 75,
        ))
        ->assertOk();

    expect($crashed->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and($other->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed);
});

test('a crash of a superseded deployment does not touch the project', function (): void {
    $node = crashNode();
    [$project, $current] = liveWorkload($node);
    $old = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 0,
    ]);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $old->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($current->id);
});

test('a node cannot change the state of a project served by another node', function (): void {
    $node = crashNode();
    $otherNode = crashNode('other-token');
    [$running, $runningDeployment] = liveWorkload($otherNode);
    [$crashed] = liveWorkload($otherNode, ['runtime_status' => RuntimeStatus::Crashed]);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($running->id, $runningDeployment->id)]))
        ->assertOk();

    expect($running->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($crashed->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed);
});

test('an entry pairing the deployment with another project is ignored', function (): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);
    [$otherProject] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($otherProject->id, $deployment->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($otherProject->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('intentionally stopped or suspended projects do not read as crashed', function (array $overrides): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node, $overrides);
    $status = $project->runtime_status;

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe($status);
})->with([
    'stopped' => [['runtime_status' => RuntimeStatus::Stopped]],
    'suspended' => [['status' => ProjectStatus::Suspended]],
]);

test('a stopped project has no active deployment, so a late crash report cannot flip it', function (): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);
    $project->update(['runtime_status' => RuntimeStatus::Stopped, 'active_deployment_id' => null]);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Stopped);
});

test('a successful deploy brings a crashed project back to running', function (): void {
    $node = crashNode();
    [$project, $old] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
    $new = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Routing,
        'sequence' => 2,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $new->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    $this->withHeaders(crashHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($project->fresh()->active_deployment_id)->toBe($new->id);

    // The old container may still be reported; it no longer speaks for the project.
    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $old->id)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});

test('a successful restart of the current workload sets running', function (AgentCommandType $type): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => $type,
        'status' => AgentCommandStatus::Running,
    ]);

    $this->withHeaders(crashHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
})->with([AgentCommandType::RestartProject, AgentCommandType::WakeProject]);

test('a restart of a superseded workload does not change the project', function (): void {
    $node = crashNode();
    [$project, $current] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
    $old = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 0,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $old->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Running,
    ]);

    $this->withHeaders(crashHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed)
        ->and($project->fresh()->active_deployment_id)->toBe($current->id);
});

test('a full list costs one project read and no write in the steady state', function (): void {
    $node = crashNode();
    $details = [];

    foreach (range(1, 50) as $ignored) {
        [$project, $deployment] = liveWorkload($node, ['runtime_status' => RuntimeStatus::Crashed]);
        $details[] = crashEntry($project->id, $deployment->id);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload($details))
        ->assertOk();

    $projectQueries = array_filter($queries, static fn (string $sql): bool => str_contains($sql, '"projects"'));
    $projectWrites = array_filter($projectQueries, static fn (string $sql): bool => str_starts_with($sql, 'update'));

    expect($projectQueries)->toHaveCount(1)
        ->and($projectWrites)->toBeEmpty();
});

test('valid crash details are accepted, including nulls and fractional timestamps', function (array $overrides): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id, $overrides)]))
        ->assertOk();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Crashed);
})->with([
    'null exit code and finished_at' => [['exit_code' => null, 'finished_at' => null, 'oom_killed' => false]],
    'docker nanosecond timestamp' => [['finished_at' => '2026-11-05T08:00:00.123456789Z']],
    'offset timestamp' => [['finished_at' => '2026-11-05T15:00:00+07:00']],
]);

test('a v0.2.0 heartbeat without the new fields stays accepted', function (string $fixture): void {
    $node = crashNode();

    $payload = agentHeartbeatFixture($fixture);

    expect($payload['metadata']['workloads'] ?? [])->not->toHaveKey('crashed_details');

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertOk();
})->with(['docker-ready', 'noop-degraded']);

test('malformed crash details reject the whole heartbeat', function (callable $mutate, string $errorKey): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);
    $payload = crashPayload([crashEntry($project->id, $deployment->id)]);

    $mutate($payload);

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$errorKey]);

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
})->with([
    'not a list' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'] = 'none';
    }, 'metadata.workloads.crashed_details'],
    'null list' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'] = null;
    }, 'metadata.workloads.crashed_details'],
    'item is not an object' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'] = ['oops'];
    }, 'metadata.workloads.crashed_details.0'],
    'project_id missing' => [function (array &$payload): void {
        unset($payload['metadata']['workloads']['crashed_details'][0]['project_id']);
    }, 'metadata.workloads.crashed_details.0.project_id'],
    'project_id not a uuid' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'][0]['project_id'] = 'not-a-uuid';
    }, 'metadata.workloads.crashed_details.0.project_id'],
    'deployment_id null' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'][0]['deployment_id'] = null;
    }, 'metadata.workloads.crashed_details.0.deployment_id'],
    'exit_code missing' => [function (array &$payload): void {
        unset($payload['metadata']['workloads']['crashed_details'][0]['exit_code']);
    }, 'metadata.workloads.crashed_details.0.exit_code'],
    'exit_code not an integer' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'][0]['exit_code'] = 'boom';
    }, 'metadata.workloads.crashed_details.0.exit_code'],
    'oom_killed not a boolean' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'][0]['oom_killed'] = 1;
    }, 'metadata.workloads.crashed_details.0.oom_killed'],
    'finished_at missing' => [function (array &$payload): void {
        unset($payload['metadata']['workloads']['crashed_details'][0]['finished_at']);
    }, 'metadata.workloads.crashed_details.0.finished_at'],
    'finished_at not a date' => [function (array &$payload): void {
        $payload['metadata']['workloads']['crashed_details'][0]['finished_at'] = 'yesterday-ish';
    }, 'metadata.workloads.crashed_details.0.finished_at'],
    'count missing beside a list' => [function (array &$payload): void {
        unset($payload['metadata']['detail_counts']['crashed_details']);
    }, 'metadata.detail_counts.crashed_details'],
    'count negative' => [function (array &$payload): void {
        $payload['metadata']['detail_counts']['crashed_details'] = -1;
    }, 'metadata.detail_counts.crashed_details'],
]);

test('more than 50 crash details are rejected', function (): void {
    $node = crashNode();
    $entry = crashEntry(
        '019a0000-0000-7000-8000-000000000001',
        '019a0000-0000-7000-8000-000000000002',
    );

    $this->withHeaders(crashHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', crashPayload(array_fill(0, 51, $entry)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.workloads.crashed_details']);
});

test('an unauthenticated heartbeat cannot report a crash', function (): void {
    $node = crashNode();
    [$project, $deployment] = liveWorkload($node);

    $this->withHeaders(crashHeaders($node, 'wrong-token'))
        ->postJson('/api/agent/v1/heartbeat', crashPayload([crashEntry($project->id, $deployment->id)]))
        ->assertUnauthorized();

    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);
});
