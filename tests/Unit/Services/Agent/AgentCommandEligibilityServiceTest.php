<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeNode(
    AgentNodeStatus $status,
    array $capabilities,
): AgentNode {
    return AgentNode::factory()->create([
        'status' => $status,
        'capabilities' => $capabilities,
    ]);
}

test('node is command-eligible only for ready, busy, and degraded states', function (): void {
    $service = new AgentCommandEligibilityService;

    expect($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Ready, [])))->toBeTrue()
        ->and($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Busy, [])))->toBeTrue()
        ->and($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Degraded, [])))->toBeTrue()
        ->and($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Draining, [])))->toBeFalse()
        ->and($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Drained, [])))->toBeFalse()
        ->and($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Maintenance, [])))->toBeFalse()
        ->and($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Offline, [])))->toBeFalse();
});

test('node has capability when it intersects the required set', function (): void {
    $service = new AgentCommandEligibilityService;
    $node = makeNode(AgentNodeStatus::Ready, ['docker-runtime', 'caddy-file-routing']);

    expect($service->nodeHasCapabilityFor($node, AgentCommandType::HealthCheck))->toBeTrue()
        ->and($service->nodeHasCapabilityFor($node, AgentCommandType::RefreshRoute))->toBeTrue()
        ->and($service->nodeHasCapabilityFor($node, AgentCommandType::DeployProject))->toBeFalse();
});

test('node is eligible for a type only when state and capability both pass', function (): void {
    $service = new AgentCommandEligibilityService;

    $ready = makeNode(AgentNodeStatus::Ready, ['docker-runtime']);
    $draining = makeNode(AgentNodeStatus::Draining, ['docker-runtime']);

    expect($service->nodeIsEligibleFor($ready, AgentCommandType::HealthCheck))->toBeTrue()
        ->and($service->nodeIsEligibleFor($draining, AgentCommandType::HealthCheck))->toBeFalse()
        ->and($service->nodeIsEligibleFor($ready, AgentCommandType::RefreshRoute))->toBeFalse();
});

test('eligible type values return only types covered by node capabilities', function (): void {
    $service = new AgentCommandEligibilityService;

    $node = makeNode(AgentNodeStatus::Ready, ['docker-runtime']);
    $values = $service->eligibleTypeValues($node);

    expect($values)->toContain('HealthCheck')
        ->and($values)->toContain('RestartProject')
        ->and($values)->not->toContain('DeployProject')
        ->and($values)->not->toContain('RefreshRoute');

    expect($service->eligibleTypeValues(makeNode(AgentNodeStatus::Ready, [])))->toBe([]);
});
