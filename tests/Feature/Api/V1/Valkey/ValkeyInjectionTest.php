<?php

declare(strict_types=1);

use App\Actions\Agent\AssignPendingCommandsAction;
use App\Actions\Schedule\DispatchSchedulesAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\ValkeyStatus;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Exceptions\Valkey\ValkeyPasswordRotatingException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\EnvironmentVariable;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Services\Valkey\ValkeyConnectionEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

/** @return array<string, string> */
function valkeyDeployedEnvironment(array $payload): array
{
    return array_map(static fn (string $value): string => Crypt::decryptString($value), $payload['environment']);
}

/**
 * A team project with a ready store on a Valkey runtime node, attached.
 *
 * @return array{team: array<string, mixed>, project: Project, node: AgentNode, valkey: ManagedValkey}
 */
function projectWithValkey(array $valkey = []): array
{
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject(['APP_ENV' => 'production'], ['release_command' => null]);
    $store = valkeyIn($team['workspace'], $node, $valkey);
    attachValkey($store, $project);

    return ['team' => $team, 'project' => $project->fresh(), 'node' => $node, 'valkey' => $store];
}

test('the variable names are fixed, and REDIS_URL carries no database number', function (): void {
    expect(ValkeyConnectionEnvironment::names())->toBe(['REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'])
        ->not->toContain('REDIS_DB', 'REDIS_USERNAME', 'REDIS_CLIENT', 'QUEUE_CONNECTION', 'CACHE_STORE', 'SESSION_DRIVER')
        ->and(ValkeyConnectionEnvironment::url('10.213.0.2', 6379, 'abc'))->toBe('redis://:abc@10.213.0.2:6379')
        ->and(ValkeyConnectionEnvironment::url('10.213.0.2', 6379, 'abc'))->not->toMatch('#:6379/\d#');
});

test('a deployment with an attached store joins its network and receives exactly the store variables, in order', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node, 'valkey' => $valkey] = projectWithValkey();
    $password = (string) $valkey->password;

    $deployment = rollbackDeploy($project, $team['owner'], WORKER_COMMIT);
    $payload = deployPayloadOf($deployment);

    expect($deployment->agent_node_id)->toBe($node->id)
        ->and($payload['valkey'])->toBe(['network' => ManagedValkey::networkNameFor($valkey->id), 'host' => '10.213.0.2'])
        ->and(valkeyDeployedEnvironment($payload))->toBe([
            'APP_ENV' => 'production',
            'REDIS_URL' => "redis://:{$password}@10.213.0.2:6379",
            'REDIS_HOST' => '10.213.0.2',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => $password,
        ])
        ->and(json_encode($payload))->not->toContain($password)
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->pluck('key')->all())->toBe(['APP_ENV']);
});

test('the payload field and variables have the shape of the agent fixture', function (): void {
    ['team' => $team, 'project' => $project] = projectWithValkey();
    $fixture = agentCommandFixture('deploy-project-valkey')['payload'];

    $payload = deployPayloadOf(rollbackDeploy($project, $team['owner'], WORKER_COMMIT));
    $fixtureRedis = array_values(array_filter(array_keys($fixture['environment']), static fn (string $key): bool => str_starts_with($key, 'REDIS_')));

    expect(array_keys($payload['valkey']))->toBe(array_keys($fixture['valkey']))
        ->and(array_map(get_debug_type(...), $payload['valkey']))->toBe(array_map(get_debug_type(...), $fixture['valkey']))
        ->and($payload['valkey']['network'])->toMatch(ManagedValkey::NETWORK_PATTERN)
        ->and(array_values(array_filter(array_keys(valkeyDeployedEnvironment($payload)), static fn (string $key): bool => str_starts_with($key, 'REDIS_'))))->toBe($fixtureRedis)
        // The fixture's URL has no database number either.
        ->and($fixture['environment']['REDIS_URL'])->toMatch('#^redis://:[A-Za-z0-9]+@[0-9.]+:6379$#');
});

test('a project variable with the same name wins, the network is joined anyway, and the project shows which', function (): void {
    ['team' => $team, 'project' => $project] = projectWithValkey();
    EnvironmentVariable::query()->create(['project_id' => $project->id, 'key' => 'REDIS_URL', 'encrypted_value' => 'redis://elsewhere:6379/0', 'is_secret' => true]);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.valkeys.0.overridden_variables', ['REDIS_URL'])
        ->assertJsonPath('data.valkeys.0.variables', ['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD']);

    $this->getJson('/api/v1/app/projects?workspace_id='.$team['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.0.valkeys.0.status', 'ready');

    $payload = deployPayloadOf(rollbackDeploy($project->fresh(), $team['owner'], WORKER_COMMIT));

    expect(valkeyDeployedEnvironment($payload)['REDIS_URL'])->toBe('redis://elsewhere:6379/0')
        ->and($payload)->toHaveKey('valkey');
});

test('while SAKALA_VALKEY_ENABLED is off an attached store changes nothing: no field, no variables, no redeploy hint, no project key', function (): void {
    ['team' => $team, 'project' => $project] = projectWithValkey();
    enableValkey(false);

    $live = deployLive($project, $team['owner']);
    $payload = deployPayloadOf($live);

    expect($payload)->not->toHaveKey('valkey')
        ->and(array_keys(valkeyDeployedEnvironment($payload)))->toBe(['APP_ENV'])
        ->and($project->fresh()->requiresRedeploy())->toBeFalse();

    $this->actingAs($team['owner'], 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertOk()->assertJsonMissingPath('data.valkeys');
    $this->getJson('/api/v1/app/projects?workspace_id='.$team['workspace']->id)->assertOk()->assertJsonMissingPath('data.0.valkeys');

    // Turning it on makes the store part of what the next deployment gets.
    enableValkey();

    expect($project->fresh()->requiresRedeploy())->toBeTrue();
});

test('a deployment is refused while the store is not ready or its password is uncertain', function (): void {
    ['team' => $team, 'project' => $project, 'valkey' => $valkey] = projectWithValkey();

    $valkey->update(['status' => ValkeyStatus::Provisioning]);
    expect(fn () => rollbackDeploy($project, $team['owner'], WORKER_COMMIT))->toThrow(ValkeyNotReadyException::class);

    $valkey->update(['status' => ValkeyStatus::Ready]);
    issuedValkeyCommand($valkey, AgentCommandType::RotateValkeyPassword, AgentCommandStatus::Claimed);
    expect(fn () => rollbackDeploy($project, $team['owner'], WORKER_COMMIT))->toThrow(ValkeyPasswordRotatingException::class);

    // A locked store still injects: the application gets an auth error.
    AgentCommand::query()->update(['status' => AgentCommandStatus::Succeeded]);
    $valkey->update(['status' => ValkeyStatus::Locked]);
    expect(deployPayloadOf(rollbackDeploy($project, $team['owner'], WORKER_COMMIT)))->toHaveKey('valkey');
});

test('a manual deployment answers 409 VALKEY_NOT_READY through the API', function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => WORKER_COMMIT, 'commit' => ['message' => 'feat: valkey']],
        ], 200),
    ]);
    ['team' => $team, 'project' => $project, 'valkey' => $valkey] = projectWithValkey();
    $valkey->update(['status' => ValkeyStatus::Failed]);

    $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_NOT_READY')
        ->assertJsonPath('status', 'failed');
});

test('the deployment goes to the store\'s node, never to an emptier one, and waits for it while it is offline', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $storeNode] = projectWithValkey();
    // An emptier node with the same capabilities would win the capacity ranking.
    valkeyNode()->update(['metadata' => ['workloads' => [], 'resources' => ['memory_available_bytes' => PHP_INT_MAX >> 1]]]);

    expect(rollbackDeploy($project, $team['owner'], WORKER_COMMIT)->agent_node_id)->toBe($storeNode->id);

    AgentCommand::query()->update(['status' => AgentCommandStatus::Succeeded]);
    $project->deployments()->update(['status' => 'failed']);
    $storeNode->update(['last_seen_at' => now()->subHour(), 'status' => AgentNodeStatus::Offline]);

    $waiting = rollbackDeploy($project, $team['owner'], ROLLBACK_NEW_COMMIT);
    $command = AgentCommand::query()->where('deployment_id', $waiting->id)->sole();

    expect($waiting->agent_node_id)->toBeNull()
        ->and($command->requestedValkeyNodeId())->toBe($storeNode->id)
        ->and(app(AssignPendingCommandsAction::class)->handle())->toBe(0);

    $storeNode->update(['last_seen_at' => now(), 'status' => AgentNodeStatus::Ready]);

    expect(app(AssignPendingCommandsAction::class)->handle())->toBe(1)
        ->and($command->fresh()->agent_node_id)->toBe($storeNode->id);
});

test('a project already served by another node than its store is refused with VALKEY_NODE_MISMATCH', function (): void {
    ['team' => $team, 'project' => $project, 'valkey' => $valkey] = projectWithValkey();
    $valkey->attachments()->delete();
    deployLive($project->fresh(), $team['owner']);
    $valkey->update(['node_id' => valkeyNode()->id]);
    attachValkey($valkey, $project->fresh());

    expect(fn () => rollbackDeploy($project->fresh(), $team['owner'], ROLLBACK_NEW_COMMIT))->toThrow(ValkeyNodeMismatchException::class);
});

test('a scheduled job copies the live deployment\'s store network, in the shape of the agent fixture', function (): void {
    enableWorkersAndSchedules();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:15:20', 'UTC'));
    ['team' => $team, 'project' => $project] = projectWithValkey();
    $live = deployLive($project, $team['owner']);
    scheduleFor($project, ['cron_expression' => '*/15 * * * *', 'next_run_at' => CarbonImmutable::parse('2026-11-24 10:15:00', 'UTC')]);

    app(DispatchSchedulesAction::class)->handle();

    $job = AgentCommand::query()->where('type', AgentCommandType::RunJob)->sole();
    $fixture = agentCommandFixture('run-job-valkey')['data'][0]['payload'];

    expect($job->payload['valkey'])->toBe(deployPayloadOf($live)['valkey'])
        ->and(array_keys($job->payload['valkey']))->toBe(array_keys($fixture['valkey']))
        ->and(array_keys(valkeyDeployedEnvironment($job->payload)))->toContain('REDIS_URL', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD');
});
