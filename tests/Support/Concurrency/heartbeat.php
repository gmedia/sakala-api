<?php

declare(strict_types=1);

/*
 * One agent heartbeat in its own PHP process, and so its own PostgreSQL
 * session, for the concurrency suite (HeartbeatCommandLockOrderTest). It
 * inherits the test's environment, so it reaches the same database.
 *
 * Argument: JSON {"node_id": string, "traffic": list, "crashed": list|null},
 * where "traffic" and "crashed" are the heartbeat's
 * `metadata.workloads.traffic` and `crashed_details` lists.
 */

use App\Actions\Agent\HeartbeatAgentAction;
use App\Data\Agent\AgentHeartbeatData;
use App\Data\Agent\CrashedWorkloadsData;
use App\Data\Agent\WorkloadTrafficData;
use App\Models\AgentNode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array{node_id: string, traffic: list<array{project_id: string, deployment_id: string, last_request_at: string}>, crashed: list<array{project_id: string, deployment_id: string, process?: string}>|null} $input */
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);

$node = AgentNode::query()->findOrFail($input['node_id']);

// The console exception handler would print a failure and still exit 0.
try {
    $app->make(HeartbeatAgentAction::class)->handle($node, new AgentHeartbeatData(
        hostname: (string) $node->hostname,
        runtimeNetwork: (string) $node->runtime_network,
        capabilities: $node->capabilities ?? [],
        metadata: $node->metadata ?? [],
        status: $node->status,
        protocolVersion: (int) $node->protocol_version,
        sentAt: CarbonImmutable::now(),
        crashedWorkloads: $input['crashed'] === null
            ? null
            : CrashedWorkloadsData::fromReport($input['crashed'], count($input['crashed'])),
        workloadTraffic: WorkloadTrafficData::fromReport($input['traffic']),
    ));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);

    exit(1);
}
