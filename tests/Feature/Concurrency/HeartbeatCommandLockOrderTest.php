<?php

declare(strict_types=1);

use App\Actions\Project\RestartProjectAction;
use App\Enums\AgentCommandType;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use App\Support\Database\TransactionRetry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** How long the heartbeat process may take to boot and reach its lock wait. */
const HEARTBEAT_LOCK_WAIT_DEADLINE_SECONDS = 20;

/** Between two looks at pg_stat_activity. */
const HEARTBEAT_LOCK_WAIT_POLL_MICROSECONDS = 50_000;

/** Upper bound for the whole heartbeat process. */
const HEARTBEAT_PROCESS_TIMEOUT_SECONDS = 60;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

/**
 * Start one heartbeat of the node in another process and return once its
 * session waits for a row lock: the project row the caller's transaction
 * holds. By then the heartbeat holds the node row and whatever it locked
 * before the project.
 *
 * @param  array<string, mixed>  $input
 */
function heartbeatWaitingForProject(array $input): Process
{
    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/Concurrency/heartbeat.php'), json_encode($input, JSON_THROW_ON_ERROR)],
        timeout: HEARTBEAT_PROCESS_TIMEOUT_SECONDS,
    );
    $process->start();

    $observer = 'pgsql_observer';
    config(["database.connections.{$observer}" => config('database.connections.'.DB::getDefaultConnection())]);
    DB::purge($observer);

    $deadline = microtime(true) + HEARTBEAT_LOCK_WAIT_DEADLINE_SECONDS;

    try {
        while (microtime(true) < $deadline) {
            $waiting = DB::connection($observer)->selectOne(
                "select count(*) as waiting from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()",
            );

            if ((int) $waiting->waiting > 0) {
                return $process;
            }

            if (! $process->isRunning()) {
                throw new RuntimeException('The heartbeat ended before it waited for the project: '.$process->getErrorOutput().$process->getOutput());
            }

            usleep(HEARTBEAT_LOCK_WAIT_POLL_MICROSECONDS);
        }
    } finally {
        DB::purge($observer);
    }

    $process->stop();

    throw new RuntimeException('The heartbeat never waited for the project row.');
}

/*
 * The deadlock seen on staging: a restart holds the project row and inserts
 * its command, whose foreign key checks take FOR KEY SHARE on the node and
 * the deployment; the heartbeat of that node holds the node (and, while it
 * reconciles crashes, the deployment) and then writes the project. The
 * heartbeat runs for real in its own process; the restart's command insert
 * is held back until the heartbeat waits for the project, which is the
 * interleaving that deadlocked.
 */
test('a restart does not deadlock with a heartbeat of the node that serves the project', function (RuntimeStatus $runtimeStatus, bool $workerCrashing, ?bool $reportCrashes): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project, $deployment] = idleSleepProject($node, ['runtime_status' => $runtimeStatus], owner: $owner);
    $lastRequestAt = now()->subMinute()->startOfSecond();

    $crashed = match (true) {
        $workerCrashing => [['project_id' => $project->id, 'deployment_id' => $deployment->id, 'process' => 'worker', 'exit_code' => 1]],
        $reportCrashes === true => [],
        default => null,
    };

    $heartbeat = null;

    AgentCommand::creating(function () use (&$heartbeat, $node, $project, $deployment, $lastRequestAt, $crashed): void {
        if ($heartbeat !== null) {
            return;
        }

        $heartbeat = heartbeatWaitingForProject([
            'node_id' => $node->id,
            'traffic' => [[
                'project_id' => $project->id,
                'deployment_id' => $deployment->id,
                'last_request_at' => $lastRequestAt->toIso8601ZuluString(),
            ]],
            'crashed' => $crashed,
        ]);
    });

    try {
        $result = app(RestartProjectAction::class)->handle($project, $owner, null);
    } finally {
        $heartbeat?->wait();
    }

    expect($heartbeat)->not->toBeNull()
        ->and($heartbeat->getExitCode())->toBe(0, $heartbeat->getErrorOutput())
        ->and($result->command->type)->toBe(AgentCommandType::RestartProject)
        ->and($result->command->agent_node_id)->toBe($node->id)
        ->and(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->count())->toBe(1);

    $project = Project::query()->findOrFail($project->id);

    expect($project->last_request_at?->equalTo($lastRequestAt))->toBeTrue()
        ->and($project->runtime_status)->toBe(RuntimeStatus::Running);
})->with([
    // The node row: every heartbeat holds it, then writes last_request_at.
    'traffic only' => [RuntimeStatus::Running, false, null],
    // The deployment row too: RecordWorkerCrashesAction locks it first.
    'a crashing worker' => [RuntimeStatus::Running, true, null],
    // ReconcileCrashedWorkloadsAction locks the deployment, then the project.
    'a crashed project that recovered' => [RuntimeStatus::Crashed, false, true],
]);

test('a restart chosen as a deadlock victim runs again instead of failing', function (): void {
    $node = idleSleepNode();
    $owner = User::factory()->create();
    [$project] = idleSleepProject($node, owner: $owner);
    $inserts = 0;

    // PostgreSQL aborts the first attempt the way it did on staging.
    AgentCommand::creating(function () use (&$inserts): void {
        $inserts++;

        if ($inserts === 1) {
            throw new QueryException(
                DB::getDefaultConnection(),
                'insert into "agent_commands"',
                [],
                new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'),
            );
        }
    });

    $result = app(RestartProjectAction::class)->handle($project, $owner, 'restart-after-deadlock');

    expect($inserts)->toBe(2)
        ->and($inserts)->toBeLessThanOrEqual(TransactionRetry::ATTEMPTS)
        ->and(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->count())->toBe(1)
        ->and(ProjectControlRequest::query()->where('idempotency_key', 'restart-after-deadlock')->value('agent_command_id'))
        ->toBe($result->command->id);
});
