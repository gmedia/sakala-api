<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Services\Agent\AgentNodeSchedulerService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sweepNode(array $overrides = []): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime'],
        'last_seen_at' => now(),
        ...$overrides,
    ]);
}

test('nodes that missed the heartbeat window are marked offline and audited', function (): void {
    config(['sakala.agent.offline_after_seconds' => 60]);
    $stale = sweepNode(['last_seen_at' => now()->subSeconds(61)]);
    $fresh = sweepNode(['last_seen_at' => now()->subSeconds(30)]);
    $neverSeen = sweepNode(['last_seen_at' => null, 'status' => AgentNodeStatus::Degraded]);
    $alreadyOffline = sweepNode(['last_seen_at' => now()->subHour(), 'status' => AgentNodeStatus::Offline]);

    $this->artisan('agent:mark-offline-nodes')
        ->expectsOutputToContain('Marked 2 agent node(s) offline.')
        ->assertSuccessful();

    expect($stale->fresh()->status)->toBe(AgentNodeStatus::Offline)
        ->and($neverSeen->fresh()->status)->toBe(AgentNodeStatus::Offline)
        ->and($fresh->fresh()->status)->toBe(AgentNodeStatus::Ready)
        ->and($alreadyOffline->fresh()->status)->toBe(AgentNodeStatus::Offline)
        ->and(AuditEvent::query()->where('action', 'agent.node.marked_offline')->count())->toBe(2);

    // The sweep is idempotent.
    $this->artisan('agent:mark-offline-nodes')->expectsOutputToContain('Marked 0 agent node(s) offline.');
});

test('an offline node is skipped by the scheduler and restored by its next heartbeat', function (): void {
    $token = 'sweep-token';
    $node = sweepNode([
        'last_seen_at' => now()->subMinutes(5),
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);

    $this->artisan('agent:mark-offline-nodes');
    expect($node->fresh()->status)->toBe(AgentNodeStatus::Offline)
        ->and(app(AgentNodeSchedulerService::class)->selectNodeFor(AgentCommandType::HealthCheck))->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id])
        ->postJson('/api/agent/v1/heartbeat', heartbeatPayload(['capabilities' => ['docker-runtime']]))
        ->assertOk();

    expect($node->fresh()->status)->toBe(AgentNodeStatus::Ready)
        ->and(app(AgentNodeSchedulerService::class)->selectNodeFor(AgentCommandType::HealthCheck)?->id)->toBe($node->id);
});

test('the offline sweep is scheduled every minute', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'agent:mark-offline-nodes'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});
