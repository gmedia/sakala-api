<?php

declare(strict_types=1);

use App\Models\AgentNode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function placementHeartbeat(array $runtime, array $workloads = []): array
{
    $payload = heartbeatPayload(['metadata' => ['workloads' => $workloads]]);
    $payload['metadata'][AgentNode::RUNTIME_METADATA_KEY] = $runtime;

    return $payload;
}

test('a heartbeat stores the reported region, edge address, and container maximum', function (): void {
    $node = idleSleepNode();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', placementHeartbeat(
            ['region' => 'jkt', 'edge_address' => '10.0.47.201:8180'],
            [AgentNode::MAXIMUM_ACTIVE_WORKLOADS_METADATA_KEY => 5],
        ))
        ->assertOk();

    $node->refresh();

    expect($node->reportedRegion())->toBe('jkt')
        ->and($node->effectiveRegion())->toBe('jkt')
        ->and($node->reportedEdgeAddress())->toBe('10.0.47.201:8180')
        ->and($node->effectiveEdgeAddress())->toBe('10.0.47.201:8180')
        ->and($node->capacity()->maximumActiveWorkloads)->toBe(5)
        ->and($node->capacity()->activeWorkloads)->toBe(2)
        ->and($node->capacity()->freeWorkloadSlots())->toBe(3)
        ->and($node->capacity()->memoryAvailableBytes)->toBe(4294967296);
});

test('the fields are optional and nullable', function (array $runtime): void {
    $node = idleSleepNode();
    $payload = heartbeatPayload();

    if ($runtime !== []) {
        $payload['metadata'][AgentNode::RUNTIME_METADATA_KEY] = $runtime;
    }

    $this->withHeaders(idleSleepHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();

    expect($node->fresh()->effectiveRegion())->toBeNull()
        ->and($node->fresh()->effectiveEdgeAddress())->toBeNull()
        ->and($node->fresh()->capacity()->freeWorkloadSlots())->toBeNull();
})->with([
    'absent' => [[]],
    'null' => [['region' => null, 'edge_address' => null]],
]);

test('accepted edge address forms', function (string $address): void {
    $node = idleSleepNode();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', placementHeartbeat(['edge_address' => $address]))
        ->assertOk();

    expect($node->fresh()->effectiveEdgeAddress())->toBe($address);
})->with([
    'ipv4' => ['10.0.47.201:8180'],
    'ipv6' => ['[fd00::21]:8180'],
    'hostname' => ['runtime-01.internal:8180'],
]);

test('a malformed region or edge address rejects the heartbeat', function (array $runtime, string $field): void {
    $node = idleSleepNode();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', placementHeartbeat($runtime))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'uppercase region' => [['region' => 'JKT'], 'metadata.runtime.region'],
    'region with spaces' => [['region' => 'east java'], 'metadata.runtime.region'],
    'region too long' => [['region' => str_repeat('a', 33)], 'metadata.runtime.region'],
    'address with scheme' => [['edge_address' => 'http://10.0.47.201:8180'], 'metadata.runtime.edge_address'],
    'address without port' => [['edge_address' => '10.0.47.201'], 'metadata.runtime.edge_address'],
    'address with path' => [['edge_address' => '10.0.47.201:8180/x'], 'metadata.runtime.edge_address'],
    'port out of range' => [['edge_address' => '10.0.47.201:70000'], 'metadata.runtime.edge_address'],
    'invalid ipv4' => [['edge_address' => '10.0.47.300:8180'], 'metadata.runtime.edge_address'],
    'unbracketed ipv6' => [['edge_address' => 'fd00::21:8180'], 'metadata.runtime.edge_address'],
]);

test('a negative container maximum rejects the heartbeat', function (): void {
    $node = idleSleepNode();

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', placementHeartbeat([], [AgentNode::MAXIMUM_ACTIVE_WORKLOADS_METADATA_KEY => -1]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('metadata.workloads.maximum_active_workloads');
});

test('an admin override wins over what the node reports', function (): void {
    $node = idleSleepNode(['region_override' => 'jog', 'edge_address_override' => '172.16.0.5:8180']);

    $this->withHeaders(idleSleepHeaders($node))
        ->postJson('/api/agent/v1/heartbeat', placementHeartbeat(['region' => 'jkt', 'edge_address' => '10.0.47.201:8180']))
        ->assertOk();

    $node->refresh();

    // The heartbeat never touches the overrides.
    expect($node->region_override)->toBe('jog')
        ->and($node->effectiveRegion())->toBe('jog')
        ->and($node->reportedRegion())->toBe('jkt')
        ->and($node->effectiveEdgeAddress())->toBe('172.16.0.5:8180')
        ->and($node->reportedEdgeAddress())->toBe('10.0.47.201:8180');
});
