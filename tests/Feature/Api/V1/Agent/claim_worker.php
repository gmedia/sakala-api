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
 * Multi-process helper for the claim contention test.
 *
 * Each invocation boots a standalone Laravel app pointed at a shared SQLite
 * file (via DB_CONNECTION/DB_DATABASE env) so every process sees the same
 * data — something in-memory SQLite can never provide.
 *
 * Modes:
 *   seed   <outJson>
 *       migrate:fresh the shared file, create 5 claimable agents and one
 *       Pending command, and write {command_id, agents:[{agent_id,token}]}
 *       to <outJson>.
 *
 *   claim  <inputJson> <agentId> <goFile> <readyFile>
 *       signal readiness, wait for the start-barrier (<goFile>), then send a
 *       real HTTP POST to the claim endpoint through the full kernel
 *       (EnsureAgentToken middleware included) using the shared file DB.
 *
 *   verify <inputJson>
 *       print the final persisted state of the command.
 *
 * Output contract:
 *   seed   -> SEED_OK
 *   claim  -> HTTP_200 | HTTP_409 | HTTP_OTHER:<code> | WORKER_ERROR:<msg>
 *   verify -> STATE:<status>:<attempts>:<owned_by_agent>
 *   any    -> WORKER_ERROR:<msg> on unexpected failure
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

    case 'claim':
        exit(runClaim($app, $arg2, $arg3, $arg4, $arg5));

    case 'verify':
        exit(runVerify($app, $arg2));

    default:
        fwrite(STDERR, "usage: claim_worker.php {seed|claim|verify} ...\n");
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

    for ($i = 1; $i <= 5; $i++) {
        $token = 'race-agent-token-'.$i;
        $agent = AgentNode::factory()->create([
            'agent_id' => 'race-agent-'.$i,
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
        echo "WORKER_ERROR:write: cannot write {$outJson}\n";

        return 7;
    }

    echo "SEED_OK\n";

    return 0;
}

function runClaim(
    Application $app,
    string $inputJson,
    string $agentId,
    string $goFile,
    string $readyFile,
): int {
    $data = json_decode((string) file_get_contents($inputJson), true, 512, JSON_THROW_ON_ERROR);
    $commandId = $data['command_id'];
    $entry = null;

    foreach ($data['agents'] as $candidate) {
        if ($candidate['agent_id'] === $agentId) {
            $entry = $candidate;
            break;
        }
    }

    if ($entry === null) {
        echo "WORKER_ERROR:agent: {$agentId} not in seed json\n";

        return 8;
    }

    touch($readyFile);

    // Start barrier: spin until the test flips the go file, so every worker
    // fires its claim at (near) the same instant.
    $deadline = microtime(true) + 60.0;

    while (! file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            echo "WORKER_ERROR: start barrier timed out\n";

            return 4;
        }

        usleep(20_000);
    }

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
