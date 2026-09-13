<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Multi-process helper for concurrent poll-then-claim race test.
 *
 * Each invocation boots a standalone Laravel app pointed at a shared SQLite
 * file so every process sees the same data.
 *
 * Modes:
 *   seed   <outJson>
 *       migrate:fresh, create 2 agents + 1 Pending command, write json.
 *
 *   poll   <inputJson> <agentId> <readyFile>
 *       signal readiness, then GET /api/agent/v1/commands.
 *
 *   claim  <inputJson> <agentId> <goFile> <readyFile>
 *       wait for barrier, then POST /api/agent/v1/commands/{id}/claim.
 *
 *   verify <inputJson>
 *       print final state of the command.
 */
$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';

$mode = $_SERVER['argv'][1] ?? '';
$arg2 = $_SERVER['argv'][2] ?? '';
$arg3 = $_SERVER['argv'][3] ?? '';
$arg4 = $_SERVER['argv'][4] ?? '';
$arg5 = $_SERVER['argv'][5] ?? '';

try {
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $e) {
    echo 'WORKER_ERROR:bootstrap: '.$e->getMessage()."\n";
    exit(3);
}

switch ($mode) {
    case 'seed':
        exit(runSeed($app, $arg2));

    case 'poll':
        exit(runPoll($app, $arg2, $arg3, $arg4));

    case 'claim':
        exit(runClaim($app, $arg2, $arg3, $arg4, $arg5));

    case 'verify':
        exit(runVerify($app, $arg2));

    default:
        fwrite(STDERR, "usage: poll_claim_worker.php {seed|poll|claim|verify} ...\n");
        exit(2);
}

function runSeed(Application $app, string $outJson): int
{
    $kernel = $app->make(Kernel::class);
    $output = $kernel->call('migrate:fresh', ['--force' => true]);

    if ($output !== 0) {
        echo 'WORKER_ERROR:migrate: '.rtrim($kernel->output())."\n";

        return 6;
    }

    $agents = [];
    $tokens = ['race-poll-token-a', 'race-poll-token-b'];

    foreach ($tokens as $i => $token) {
        $agent = AgentNode::factory()->create([
            'agent_id' => 'race-poll-agent-'.($i + 1),
            'status' => AgentNodeStatus::Ready,
            'capabilities' => ['docker-runtime'],
            'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
        ]);
        $agents[] = ['agent_id' => $agent->agent_id, 'token' => $token];
    }

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::HealthCheck,
        'status' => AgentCommandStatus::Pending,
        'available_at' => now()->subMinute(),
        'expires_at' => now()->addMinutes(10),
    ]);

    $payload = ['command_id' => $command->id, 'agents' => $agents];

    if (file_put_contents($outJson, json_encode($payload, JSON_PRETTY_PRINT)) === false) {
        echo "WORKER_ERROR:write\n";

        return 7;
    }

    echo "SEED_OK\n";

    return 0;
}

function runPoll(Application $app, string $inputJson, string $agentId, string $readyFile): int
{
    $data = json_decode((string) file_get_contents($inputJson), true, 512, JSON_THROW_ON_ERROR);
    $entry = null;

    foreach ($data['agents'] as $candidate) {
        if ($candidate['agent_id'] === $agentId) {
            $entry = $candidate;
            break;
        }
    }

    if ($entry === null) {
        echo "WORKER_ERROR:agent not found\n";

        return 8;
    }

    touch($readyFile);

    // Wait for the test to signal all pollers are ready
    $goFile = dirname($inputJson).'/poll-go';
    $deadline = microtime(true) + 30.0;

    while (! file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            echo "WORKER_ERROR:poll barrier timeout\n";

            return 4;
        }

        usleep(20_000);
    }

    $request = Request::create(
        '/api/agent/v1/commands',
        'GET',
        [],
        [],
        [],
        [
            'HTTP_AUTHORIZATION' => 'Bearer '.$entry['token'],
            'HTTP_X_AGENT_ID' => $agentId,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        '[]',
    );

    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $code = $response->getStatusCode();

    if ($code === 200) {
        $body = $response->getContent();
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $count = count($json['data'] ?? []);
        echo "HTTP_200:{$count}\n";
    } else {
        echo 'HTTP_OTHER:'.$code."\n";
    }

    return 0;
}

function runClaim(Application $app, string $inputJson, string $agentId, string $goFile, string $readyFile): int
{
    $data = json_decode((string) file_get_contents($inputJson), true, 512, JSON_THROW_ON_ERROR);
    $entry = null;

    foreach ($data['agents'] as $candidate) {
        if ($candidate['agent_id'] === $agentId) {
            $entry = $candidate;
            break;
        }
    }

    if ($entry === null) {
        echo "WORKER_ERROR:agent not found\n";

        return 8;
    }

    touch($readyFile);

    // Start barrier: spin until the test flips the go file
    $deadline = microtime(true) + 60.0;

    while (! file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            echo "WORKER_ERROR: start barrier timed out\n";

            return 4;
        }

        usleep(20_000);
    }

    $commandId = $data['command_id'];

    $request = Request::create(
        '/api/agent/v1/commands/'.$commandId.'/claim',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_AUTHORIZATION' => 'Bearer '.$entry['token'],
            'HTTP_X_AGENT_ID' => $agentId,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        '[]',
    );

    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $code = $response->getStatusCode();

    if ($code === 200) {
        echo "HTTP_200\n";
    } elseif ($code === 409) {
        echo "HTTP_409\n";
    } else {
        echo 'HTTP_OTHER:'.$code.': '.rtrim($response->getContent())."\n";
    }

    return 0;
}

function runVerify(Application $app, string $inputJson): int
{
    $data = json_decode((string) file_get_contents($inputJson), true, 512, JSON_THROW_ON_ERROR);
    $command = AgentCommand::query()->whereKey($data['command_id'])->first();

    if ($command === null) {
        echo "WORKER_ERROR:command not found\n";

        return 9;
    }

    echo 'STATE:'.($command->status->value ?? 'unknown').':'.$command->attempts.':'.($command->agent_node_id !== null ? 'owned' : 'unowned')."\n";

    return 0;
}
