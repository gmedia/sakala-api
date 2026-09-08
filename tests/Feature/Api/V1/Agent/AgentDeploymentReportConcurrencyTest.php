<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        return;
    }

    $this->artisan('migrate:fresh');
});

test('concurrent report processes allocate unique deployment log sequences', function (): void {
    $token = 'concurrent-report-token';
    $agent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create(['sequence' => 1]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Claimed,
        'payload' => [
            'log_bounds' => [
                'max_line_length' => 4096,
                'max_batch_lines' => 500,
                'max_total_bytes' => 10 * 1024 * 1024,
            ],
        ],
    ]);

    $base = sys_get_temp_dir().'/sakala-report-'.getmypid();
    $inputJson = $base.'.json';
    $goFile = $base.'.go';
    $worker = __DIR__.'/report_worker.php';
    $input = [
        'command_id' => $command->id,
        'agent_id' => $agent->agent_id,
        'token' => $token,
    ];

    file_put_contents($inputJson, json_encode($input, JSON_THROW_ON_ERROR));

    $connection = config('database.connections.'.DB::getDefaultConnection());
    $workerEnv = array_merge($_ENV, [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
        'DB_PORT' => (string) ($connection['port'] ?? '5432'),
        'DB_DATABASE' => (string) ($connection['database'] ?? ''),
        'DB_USERNAME' => (string) ($connection['username'] ?? ''),
        'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
        'DB_SSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
        'APP_BASE_PATH' => dirname(__DIR__, 5),
        'HOME' => $base,
        'PULSE_ENABLED' => 'false',
        'TELESCOPE_ENABLED' => 'false',
        'NIGHTWATCH_ENABLED' => 'false',
    ]);

    $processes = [];

    try {
        foreach ([0, 1] as $index) {
            $readyFile = "{$base}-ready-{$index}";
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $worker, 'report', $inputJson, (string) $index, $goFile, $readyFile],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $workerEnv,
            );

            expect(is_resource($process))->toBeTrue();
            $processes[$index] = compact('process', 'pipes', 'readyFile');
        }

        $deadline = microtime(true) + 60.0;

        foreach ($processes as $index => $process) {
            while (! file_exists($process['readyFile'])) {
                if (microtime(true) > $deadline) {
                    fail("worker {$index} did not reach the start barrier within 60s");
                }

                usleep(20_000);
            }
        }

        touch($goFile);
        $results = [];

        foreach ($processes as $index => $process) {
            $output = stream_get_contents($process['pipes'][1]);
            $errors = stream_get_contents($process['pipes'][2]);
            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);
            $exitCode = proc_close($process['process']);
            $results[$index] = trim($output.$errors);

            if ($exitCode !== 0) {
                fail("worker {$index} exited {$exitCode}: {$results[$index]}");
            }
        }

        expect($results)->each->toBe('HTTP_200');
    } finally {
        foreach ($processes as $process) {
            if (is_resource($process['process'])) {
                proc_terminate($process['process']);
                proc_close($process['process']);
            }
        }

        foreach (glob($base.'*') ?: [] as $leftover) {
            @unlink($leftover);
        }
    }

    expect(Deployment::query()->findOrFail($deployment->id)->logs()->orderBy('sequence')->pluck('sequence')->all())
        ->toBe([1, 2])
        ->and($command->fresh()->reported_log_bytes)->toBe(strlen('concurrent-log-0') + strlen('concurrent-log-1'));
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'Requires PostgreSQL and separate database processes.',
);
