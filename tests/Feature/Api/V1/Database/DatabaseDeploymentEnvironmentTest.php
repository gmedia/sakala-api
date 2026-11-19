<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\EnvironmentVariable;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Services\Database\DatabaseConnectionEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '9c1f0e2d3b4a5968778695a4b3c2d1e0f9a8b7c6', 'commit' => ['message' => 'feat: database']],
        ], 200),
    ]);
});

/** An online runtime node that builds and runs projects, and also hosts the databases (a single-node install). */
function databaseRuntimeNode(bool $endpoint = true): AgentNode
{
    return databaseNode(overrides: [
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', AgentCommandType::MANAGED_POSTGRES_CAPABILITY],
        'metadata' => [
            'workloads' => [],
            ...($endpoint ? ['database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT]]] : []),
        ],
    ]);
}

/** @return array{Project, ManagedDatabase, array<string, mixed>} */
function attachedProjectWithDatabase(bool $endpoint = true): array
{
    $node = databaseRuntimeNode($endpoint);
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['branch' => 'main']);
    attachDatabase($database, $project);

    return [$project, $database, $team];
}

/** @return array<string, string> */
function deployedEnvironment(AgentCommand $command): array
{
    return array_map(static fn (string $value): string => Crypt::decryptString($value), $command->payload['environment']);
}

test('a deployment of a project with an attached database receives the connection variables', function (): void {
    [$project, $database, $team] = attachedProjectWithDatabase();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'APP_ENV', 'encrypted_value' => 'production', 'is_secret' => false]);
    $password = (string) $database->password;

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $command = AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole();

    expect(deployedEnvironment($command))->toBe([
        'APP_ENV' => 'production',
        'DATABASE_URL' => "postgresql://{$database->pg_role}:{$password}@".DATABASE_NODE_HOST.':'.DATABASE_NODE_PORT."/{$database->pg_database}?sslmode=require",
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => DATABASE_NODE_HOST,
        'DB_PORT' => (string) DATABASE_NODE_PORT,
        'DB_DATABASE' => $database->pg_database,
        'DB_USERNAME' => $database->pg_role,
        'DB_PASSWORD' => $password,
    ])
        // Never stored as the project's own variables, and encrypted at rest.
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->pluck('key')->all())->toBe(['APP_ENV'])
        ->and(json_encode($command->payload))->not->toContain($password);

    // Served to the target node in plaintext, like the other variables.
    $command->update(['agent_node_id' => $database->node_id]);
    $this->withHeaders(databaseNodeHeaders($database->node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.payload.environment.DB_PASSWORD', $password);
});

test('a variable the project sets itself wins over the database one, and the project shows it', function (): void {
    [$project, $database, $team] = attachedProjectWithDatabase();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'DB_HOST', 'encrypted_value' => 'pgbouncer.internal', 'is_secret' => false]);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'DATABASE_URL', 'encrypted_value' => 'postgresql://mine', 'is_secret' => true]);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.databases.0.id', $database->id)
        ->assertJsonPath('data.databases.0.name', $database->name)
        ->assertJsonPath('data.databases.0.status', 'ready')
        ->assertJsonPath('data.databases.0.overridden_variables', ['DATABASE_URL', 'DB_HOST'])
        ->assertJsonPath('data.databases.0.variables', ['DB_CONNECTION', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);

    $this->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated();

    $environment = deployedEnvironment(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole());

    expect($environment['DB_HOST'])->toBe('pgbouncer.internal')
        ->and($environment['DATABASE_URL'])->toBe('postgresql://mine')
        ->and($environment['DB_USERNAME'])->toBe($database->pg_role);
});

test('a project without a database shows an empty list and gets no database variables', function (): void {
    databaseRuntimeNode();
    $team = databaseTeam();
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['branch' => 'main']);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.databases', []);

    $this->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated();

    $command = AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole();

    expect($command->payload['environment'])->toBe([]);
});

test('without the database node endpoint the deployment is refused, and nothing is queued', function (): void {
    [$project, $database, $team] = attachedProjectWithDatabase(endpoint: false);

    $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertExactJson([
            'message' => "Server database untuk {$database->name} belum melaporkan alamatnya, jadi proyek ini belum bisa di-deploy dengan database terpasang. Coba lagi beberapa saat lagi, atau lepas database-nya dulu.",
            'code' => 'DATABASE_ENDPOINT_UNAVAILABLE',
            'database' => ['id' => $database->id, 'name' => $database->name],
        ]);

    expect(Deployment::query()->count())->toBe(0)
        ->and(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->count())->toBe(0);
});

test('the deployment stores the fingerprint with the database, so detaching asks for a redeploy', function (): void {
    [$project, $database, $team] = attachedProjectWithDatabase();

    $deploymentId = $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->json('data.id');

    $deployment = Deployment::query()->findOrFail($deploymentId);
    $deployment->update(['status' => DeploymentStatus::Succeeded]);
    $project->update(['active_deployment_id' => $deployment->id]);

    expect($project->fresh()->requiresRedeploy())->toBeFalse();

    $this->deleteJson("/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/attachments/{$project->id}")->assertNoContent();

    $this->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.redeploy_required', true);
});

test('the database password never reaches stored deployment logs', function (string $encoding): void {
    [$project, $database] = attachedProjectWithDatabase();
    $node = $database->node;
    $deployment = Deployment::factory()->for($project)->create(['agent_node_id' => $node->id, 'status' => DeploymentStatus::Building, 'sequence' => 1]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);
    $value = match ($encoding) {
        'plain' => (string) $database->password,
        'url' => rawurlencode((string) $database->password),
        'base64' => base64_encode((string) $database->password),
    };

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [[
            'stream' => 'stdout',
            'message' => "connecting as {$value} now",
            'recorded_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    expect(DeploymentLog::query()->where('deployment_id', $deployment->id)->sole()->message)->toBe('connecting as [REDACTED] now');
})->with(['plain', 'url', 'base64']);

test('the names of the connection variables are fixed', function (): void {
    expect(DatabaseConnectionEnvironment::names())->toBe([
        'DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    ]);
});
