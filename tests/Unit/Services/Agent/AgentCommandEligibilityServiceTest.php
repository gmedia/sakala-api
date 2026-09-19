<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeNode(
    AgentNodeStatus $status,
    array $capabilities,
    array $overrides = [],
): AgentNode {
    return AgentNode::factory()->create([
        'status' => $status,
        'capabilities' => $capabilities,
        ...$overrides,
    ]);
}

test('every reported state except offline can still receive lifecycle commands', function (): void {
    $service = new AgentCommandEligibilityService;

    foreach ([AgentNodeStatus::Ready, AgentNodeStatus::Busy, AgentNodeStatus::Degraded, AgentNodeStatus::Draining, AgentNodeStatus::Drained, AgentNodeStatus::Maintenance] as $status) {
        expect($service->nodeIsCommandEligible(makeNode($status, [])))->toBeTrue($status->value);
    }

    expect($service->nodeIsCommandEligible(makeNode(AgentNodeStatus::Offline, [])))->toBeFalse();
});

test('only ready, busy, and degraded nodes accept workload', function (): void {
    $service = new AgentCommandEligibilityService;

    expect($service->nodeAcceptsWorkload(makeNode(AgentNodeStatus::Ready, [])))->toBeTrue()
        ->and($service->nodeAcceptsWorkload(makeNode(AgentNodeStatus::Busy, [])))->toBeTrue()
        ->and($service->nodeAcceptsWorkload(makeNode(AgentNodeStatus::Degraded, [])))->toBeTrue();

    foreach ([AgentNodeStatus::Draining, AgentNodeStatus::Drained, AgentNodeStatus::Maintenance, AgentNodeStatus::Offline] as $status) {
        $node = makeNode($status, ['docker-runtime']);

        expect($service->nodeAcceptsWorkload($node))->toBeFalse($status->value)
            ->and($service->nodeIsEligibleFor($node, AgentCommandType::HealthCheck))->toBeFalse($status->value)
            ->and($service->nodeIsEligibleFor($node, AgentCommandType::ResumeNode))->toBe($status !== AgentNodeStatus::Offline, $status->value);
    }
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

    // Node lifecycle commands need no capability, so a capability-less node
    // still sees exactly those.
    expect($service->eligibleTypeValues(makeNode(AgentNodeStatus::Ready, [])))
        ->toBe(['DrainNode', 'ResumeNode']);
});

test('command types without capability requirements are always allowed', function (): void {
    $service = new AgentCommandEligibilityService;
    $node = makeNode(AgentNodeStatus::Ready, []);

    expect($service->nodeHasCapabilityFor($node, AgentCommandType::DrainNode))->toBeTrue()
        ->and($service->nodeHasCapabilityFor($node, AgentCommandType::ResumeNode))->toBeTrue()
        ->and($service->nodeHasCapabilityFor($node, AgentCommandType::InspectProject))->toBeFalse();
});

test('revoked nodes and unsupported protocol revisions are never eligible', function (): void {
    $service = new AgentCommandEligibilityService;

    $revoked = makeNode(AgentNodeStatus::Ready, ['docker-runtime'], ['auth_status' => AgentAuthStatus::Revoked]);
    $legacy = makeNode(AgentNodeStatus::Ready, ['docker-runtime'], ['protocol_version' => 3]);
    $unseen = makeNode(AgentNodeStatus::Ready, ['docker-runtime'], ['protocol_version' => null]);

    expect($service->nodeIsCommandEligible($revoked))->toBeFalse()
        ->and($service->nodeIsCommandEligible($legacy))->toBeFalse()
        ->and($service->nodeIsCommandEligible($unseen))->toBeFalse()
        ->and($service->eligibleTypeValues($legacy))->toBe([])
        ->and($service->eligibleTypeValues($unseen))->toBe([]);
});

test('nodes whose desired state is not active only receive lifecycle commands', function (): void {
    $service = new AgentCommandEligibilityService;

    foreach ([AgentNodeDesiredState::Draining, AgentNodeDesiredState::Drained, AgentNodeDesiredState::Maintenance] as $desired) {
        $node = makeNode(AgentNodeStatus::Ready, ['docker-runtime', 'dockerfile-build'], ['desired_state' => $desired]);

        expect($service->nodeAcceptsWorkload($node))->toBeFalse()
            ->and($service->eligibleTypeValues($node))->toBe(['DrainNode', 'ResumeNode'])
            ->and($service->nodeIsEligibleFor($node, AgentCommandType::HealthCheck))->toBeFalse()
            ->and($service->nodeIsEligibleFor($node, AgentCommandType::DrainNode))->toBeTrue();
    }
});

test('pinned command types are only scoped to their assigned node', function (): void {
    $service = new AgentCommandEligibilityService;
    $node = makeNode(AgentNodeStatus::Ready, ['project-inspection']);
    $other = makeNode(AgentNodeStatus::Ready, ['project-inspection']);

    $unassignedInspect = AgentCommand::factory()->create([
        'type' => AgentCommandType::InspectProject,
        'agent_node_id' => null,
    ]);
    $assignedInspect = AgentCommand::factory()->create([
        'type' => AgentCommandType::InspectProject,
        'agent_node_id' => $node->id,
    ]);
    $unassignedHealth = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'agent_node_id' => null,
    ]);

    expect($service->commandIsScopedToNode($unassignedInspect, $node))->toBeFalse()
        ->and($service->commandIsScopedToNode($assignedInspect, $node))->toBeTrue()
        ->and($service->commandIsScopedToNode($assignedInspect, $other))->toBeFalse()
        ->and($service->commandIsScopedToNode($unassignedHealth, $node))->toBeTrue();
});
