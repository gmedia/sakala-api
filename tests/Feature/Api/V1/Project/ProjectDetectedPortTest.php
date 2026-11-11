<?php

declare(strict_types=1);

use App\Actions\Project\RequestProjectInspectionAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const DETECTED_PORT_SHA = 'abcdef0123456789abcdef0123456789abcdef01';

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => DETECTED_PORT_SHA, 'commit' => ['message' => 'feat: serve with nginx']],
        ], 200),
    ]);

    $this->portNode = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'project-inspection', 'dockerfile-build', 'railpack-info', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', 'port-token', (string) config('app.key')),
    ]);
    $this->portHeaders = ['Authorization' => 'Bearer port-token', 'X-Agent-Id' => $this->portNode->agent_id];
});

/**
 * Runs an inspection of the project through the agent endpoints and
 * completes it with the given result.
 *
 * @param  array<string, mixed>  $result
 */
function completeInspectionWith(Project $project, array $result): void
{
    $command = app(RequestProjectInspectionAction::class)->handle($project);
    expect($command)->not->toBeNull();

    test()->withHeaders(test()->portHeaders)
        ->postJson("/api/agent/v1/commands/{$command?->id}/claim", [])
        ->assertOk();
    test()->withHeaders(test()->portHeaders)
        ->postJson("/api/agent/v1/commands/{$command?->id}/complete", ['result' => $result])
        ->assertNoContent();
}

/**
 * A v4 inspection result; `exposed_ports` is left out unless given, like an
 * agent from before the field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dockerfileInspection(array $overrides = []): array
{
    return [
        'repository_url' => 'https://github.com/sakala-demo/static-site',
        'commit_sha' => DETECTED_PORT_SHA,
        'dockerfile_found' => true,
        'env_example_found' => false,
        'compose_found' => false,
        'manifests' => ['Dockerfile'],
        'package_manager' => null,
        'railpack' => [],
        ...$overrides,
    ];
}

/** The `container_port` of the deployment the project gets next. */
function deployedContainerPort(Project $project): int
{
    test()->actingAs($project->user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $command = AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::DeployProject)
        ->sole();

    return $command->payload['container_port'];
}

function portProject(array $attributes = []): Project
{
    return Project::factory()->for(User::factory())->create([
        'repository_url' => 'https://github.com/sakala-demo/static-site',
        'branch' => 'main',
        ...$attributes,
    ]);
}

test('a single exposed port becomes the port Sakala sets as PORT and probes', function (): void {
    $project = portProject();

    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => [80]]));

    $project->refresh();
    expect($project->detected_port)->toBe(80)
        ->and($project->inspection['exposed_ports'])->toBe([80]);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.detected_port', 80)
        ->assertJsonPath('data.configured_port', null)
        ->assertJsonPath('data.effective_port', 80)
        ->assertJsonMissingPath('data.inspection.exposed_ports');

    // The agent exports this as PORT, probes it, and compares a PORT variable
    // of the project against it for runtime_port_conflict.
    expect(deployedContainerPort($project))->toBe(80);
});

test('several exposed ports are ambiguous and keep the default', function (): void {
    $project = portProject(['detected_port' => 8080]);

    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => [80, 443]]));

    $project->refresh();
    expect($project->detected_port)->toBeNull()
        ->and($project->effectivePort())->toBe(3000)
        ->and(deployedContainerPort($project))->toBe(3000);
});

test('the port set on the project wins over the detected one', function (): void {
    $project = portProject(['configured_port' => 8080]);

    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => [80]]));

    $project->refresh();
    expect($project->detected_port)->toBe(80)
        ->and($project->effectivePort())->toBe(8080)
        ->and(deployedContainerPort($project))->toBe(8080);
});

test('a project without a Dockerfile keeps 3000', function (): void {
    $project = portProject();

    completeInspectionWith($project, dockerfileInspection([
        'dockerfile_found' => false,
        'manifests' => ['package.json'],
        'package_manager' => 'npm',
        'exposed_ports' => [],
    ]));

    $project->refresh();
    expect($project->detected_port)->toBeNull()
        ->and(deployedContainerPort($project))->toBe(3000);
});

test('an older agent without exposed_ports keeps 3000 and leaves a stored port alone', function (?int $stored, int $expected): void {
    $project = portProject(['detected_port' => $stored]);

    completeInspectionWith($project, dockerfileInspection());

    $project->refresh();
    expect($project->detected_port)->toBe($stored)
        ->and($project->inspection['exposed_ports'])->toBeNull()
        ->and(deployedContainerPort($project))->toBe($expected);
})->with([
    'new project' => [null, 3000],
    'port from a newer agent' => [8080, 8080],
]);

test('values that are not TCP port numbers are ignored', function (): void {
    $project = portProject();

    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => ['80', 0, 65536, 8080, 8080]]));

    expect($project->fresh()->detected_port)->toBe(8080);
});

test('an inspection of another directory than the project now uses detects nothing', function (): void {
    $project = portProject(['root_directory' => 'apps/web']);
    $command = app(RequestProjectInspectionAction::class)->handle($project);
    $project->update(['root_directory' => 'apps/api']);

    $this->withHeaders($this->portHeaders)->postJson("/api/agent/v1/commands/{$command?->id}/claim", [])->assertOk();
    $this->withHeaders($this->portHeaders)
        ->postJson("/api/agent/v1/commands/{$command?->id}/complete", ['result' => dockerfileInspection(['exposed_ports' => [80]])])
        ->assertNoContent();

    expect($project->fresh()->detected_port)->toBeNull();
});

test('changing the root directory forgets the detected port', function (): void {
    $project = portProject(['detected_port' => 80]);

    $this->actingAs($project->user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['root_directory' => 'apps/web'])
        ->assertOk()
        ->assertJsonPath('data.detected_port', null)
        ->assertJsonPath('data.effective_port', 3000);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['name' => 'Renamed'])->assertOk();
    $project->fresh()?->update(['detected_port' => 80]);
    $this->patchJson("/api/v1/app/projects/{$project->id}", ['name' => 'Renamed again', 'root_directory' => 'apps/web'])
        ->assertOk()
        ->assertJsonPath('data.detected_port', 80);
});

test('moving back to an inspected directory brings its port back', function (): void {
    $project = portProject(['root_directory' => 'apps/web']);
    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => [80]]));

    $this->actingAs($project->user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['root_directory' => 'apps/api'])
        ->assertJsonPath('data.detected_port', null);
    completeInspectionWith($project->fresh(), dockerfileInspection(['exposed_ports' => []]));

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['root_directory' => 'apps/web'])
        ->assertJsonPath('data.detected_port', null);
    $reused = app(RequestProjectInspectionAction::class)->handle($project->fresh());

    expect(AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::InspectProject)->count())->toBe(2)
        ->and($reused?->payload['root_directory'])->toBe('apps/web')
        ->and($project->fresh()->detected_port)->toBe(80);
});

test('a finished Dockerfile inspection from before exposed_ports is asked again, so the port is found without a new commit', function (): void {
    $project = portProject();

    // An agent from before the field inspected this commit.
    completeInspectionWith($project, dockerfileInspection());
    expect($project->refresh()->detected_port)->toBeNull();

    // After the node moves to a newer agent, the same commit is inspected again
    // instead of the old result being reused.
    $first = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::InspectProject)->sole();
    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => [80]]));

    $commands = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::InspectProject)->get();
    expect($commands)->toHaveCount(2)
        ->and($commands->pluck('id'))->toContain($first->id)
        ->and($project->refresh()->detected_port)->toBe(80)
        ->and(deployedContainerPort($project))->toBe(80);
});

test('a finished inspection that already carries exposed_ports is reused', function (): void {
    $project = portProject();
    completeInspectionWith($project, dockerfileInspection(['exposed_ports' => [80]]));

    $again = app(RequestProjectInspectionAction::class)->handle($project);

    expect(AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::InspectProject)->count())->toBe(1)
        ->and($again?->status)->toBe(AgentCommandStatus::Succeeded);
});
