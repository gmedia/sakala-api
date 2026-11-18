<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentNodeDesiredState;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Support\Project\DeploymentConfigFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '5b7c2a9d0e1f23456789abcdef0123456789abcd', 'commit' => ['message' => 'feat: fingerprint']],
        ], 200),
    ]);
});

/**
 * A team project whose profile allows replicas, with the owner signed in.
 * Nothing serves it yet.
 */
function fingerprintProject(array $attributes = []): Project
{
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['limit_profile_id' => LimitProfile::factory()->create([
        'max_active_containers' => 10,
        'max_active_deployments' => 5,
        'max_replicas' => 3,
    ])->id]);

    $project = Project::factory()->for($owner)->create(['workspace_id' => $team->id, 'branch' => 'main', ...$attributes]);

    test()->actingAs($owner, 'web');

    return $project;
}

/** Deploy through the API, then let that deployment serve, as a node completing it would. */
function serveFromDeploy(Project $project): Deployment
{
    $id = test()->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => $project->fresh()->branch])
        ->assertCreated()
        ->json('data.id');

    $deployment = Deployment::query()->findOrFail($id);
    $deployment->update(['status' => DeploymentStatus::Succeeded]);
    $project->update(['active_deployment_id' => $deployment->id, 'runtime_status' => RuntimeStatus::Running]);

    return $deployment;
}

function redeployHint(Project $project): bool
{
    return (bool) test()->getJson("/api/v1/app/projects/{$project->id}")->assertOk()->json('data.redeploy_required');
}

function fingerprintRegionNode(string $region): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'desired_state' => AgentNodeDesiredState::Active,
        'metadata' => ['runtime' => [AgentNode::REGION_METADATA_KEY => $region]],
    ]);
}

// ─── Snapshot at deploy ──────────────────────────────────────────────────────

test('a deployment stores the fingerprint of the configuration it was created with', function (): void {
    $project = fingerprintProject(['configured_port' => 8080, 'build_command' => 'npm run build', 'root_directory' => 'apps/web']);

    $deployment = serveFromDeploy($project);
    $project->refresh();

    expect($deployment->config_fingerprint)->toHaveLength(64)
        ->and($deployment->config_fingerprint)->toBe($project->deploymentConfigFingerprint())
        ->and(redeployHint($project))->toBeFalse();
});

test('a project without an environment fingerprint gets one when it deploys', function (): void {
    $project = fingerprintProject();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'API_URL', 'encrypted_value' => 'https://api.test']);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'TOKEN', 'encrypted_value' => 'secret-value-1']);

    expect($project->fresh()->environment_fingerprint)->toBeNull();

    $deployment = serveFromDeploy($project);
    $project->refresh();

    expect($project->environment_fingerprint)
        ->toBe(DeploymentConfigFingerprint::forEnvironment($project->environmentVariables()->get()))
        ->and($deployment->config_fingerprint)->toBe($project->deploymentConfigFingerprint())
        ->and(redeployHint($project))->toBeFalse();
});

test('the environment fingerprint is keyed, ignores ciphertext and order, and covers keys and values', function (): void {
    $project = fingerprintProject();
    $first = EnvironmentVariable::factory()->for($project)->create(['key' => 'B_KEY', 'encrypted_value' => 'value-b']);
    $second = EnvironmentVariable::factory()->for($project)->create(['key' => 'A_KEY', 'encrypted_value' => 'value-a']);

    $fingerprint = DeploymentConfigFingerprint::forEnvironment([$first, $second]);
    $reencrypted = $first->fresh();
    $reencrypted->encrypted_value = 'value-b';

    expect(DeploymentConfigFingerprint::forEnvironment([$second, $reencrypted]))->toBe($fingerprint)
        ->and(DeploymentConfigFingerprint::forEnvironment([$second]))->not->toBe($fingerprint)
        ->and($fingerprint)->not->toBe(hash('sha256', json_encode([['A_KEY', 'value-a'], ['B_KEY', 'value-b']], JSON_THROW_ON_ERROR)));

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect(DeploymentConfigFingerprint::forEnvironment([$first, $second]))->not->toBe($fingerprint);
});

// ─── Project settings ────────────────────────────────────────────────────────

test('settings the next deployment does not receive never ask for a redeploy', function (array $changes): void {
    fingerprintRegionNode('jkt');
    $project = fingerprintProject(['auto_deploy' => true]);
    serveFromDeploy($project);

    $response = $this->patchJson("/api/v1/app/projects/{$project->id}", $changes)
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', false);

    foreach ($changes as $field => $value) {
        $response->assertJsonPath("data.{$field}", $value);
    }

    expect($project->fresh()->config_changed_at)->toBeNull()
        ->and(redeployHint($project))->toBeFalse();
})->with([
    'name' => [['name' => 'Renamed App']],
    'thumbnail_url' => [['thumbnail_url' => 'https://example.com/thumb.png']],
    'auto_deploy' => [['auto_deploy' => false]],
    'region' => [['region' => 'jkt']],
    'all together' => [[
        'name' => 'Renamed App',
        'thumbnail_url' => 'https://example.com/thumb.png',
        'auto_deploy' => false,
        'region' => 'jkt',
    ]],
]);

test('settings the next deployment receives ask for a redeploy', function (string $field, int|string $value): void {
    $project = fingerprintProject();
    serveFromDeploy($project);

    $this->patchJson("/api/v1/app/projects/{$project->id}", [$field => $value])
        ->assertOk()
        ->assertJsonPath("data.{$field}", $value)
        ->assertJsonPath('data.redeploy_required', true);

    expect($project->fresh()->config_changed_at)->not->toBeNull();
})->with([
    'branch' => ['branch', 'develop'],
    'configured_port' => ['configured_port', 8080],
    'build_command' => ['build_command', 'npm run build'],
    'start_command' => ['start_command', 'node server.js'],
    'root_directory' => ['root_directory', 'apps/web'],
    'replicas' => ['replicas', 2],
]);

test('changing a setting back clears the redeploy hint', function (): void {
    $project = fingerprintProject(['build_command' => 'npm run build']);
    serveFromDeploy($project);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['build_command' => 'pnpm build'])
        ->assertJsonPath('data.redeploy_required', true);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['build_command' => 'npm run build'])
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', false);
});

test('a newly detected port asks for a redeploy only while no port is configured', function (?int $configuredPort, bool $expected): void {
    $project = fingerprintProject(['configured_port' => $configuredPort, 'detected_port' => 3000]);
    serveFromDeploy($project);

    // What ProjectInspectionOutcomeService writes when an inspection finds another port.
    $project->update(['detected_port' => 8080]);

    expect(redeployHint($project))->toBe($expected);
})->with([
    'no configured port' => [null, true],
    'configured port wins' => [9000, false],
]);

// ─── Environment variables ───────────────────────────────────────────────────

test('creating, changing, and deleting a variable ask for a redeploy', function (string $operation): void {
    $project = fingerprintProject();
    $variable = EnvironmentVariable::factory()->for($project)->create(['key' => 'API_URL', 'encrypted_value' => 'value-1', 'is_secret' => false]);
    serveFromDeploy($project);

    match ($operation) {
        'create' => $this->postJson(route('api.v1.app.projects.environment-variables.store', $project), [
            'key' => 'NEW_ONE', 'value' => 'value-2',
        ])->assertCreated()->assertJsonPath('data.redeploy_required', true),
        'update' => $this->patchJson(route('api.v1.app.projects.environment-variables.update', [$project, $variable]), [
            'value' => 'value-2',
        ])->assertOk()->assertJsonPath('data.redeploy_required', true),
        'delete' => $this->deleteJson(route('api.v1.app.projects.environment-variables.destroy', [$project, $variable]))
            ->assertNoContent(),
    };

    expect(redeployHint($project))->toBeTrue()
        ->and($project->fresh()->environment_fingerprint)
        ->toBe(DeploymentConfigFingerprint::forEnvironment($project->environmentVariables()->get()));
})->with(['create', 'update', 'delete']);

test('flipping is_secret alone keeps the fingerprint and asks for nothing', function (): void {
    $project = fingerprintProject();
    $variable = EnvironmentVariable::factory()->for($project)->create(['encrypted_value' => 'value-1', 'is_secret' => false]);
    serveFromDeploy($project);

    $this->patchJson(route('api.v1.app.projects.environment-variables.update', [$project, $variable]), ['is_secret' => true])
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', false);

    expect(redeployHint($project))->toBeFalse()
        ->and($project->fresh()->config_changed_at)->toBeNull();
});

test('changing a variable back clears the redeploy hint', function (): void {
    $project = fingerprintProject();
    $variable = EnvironmentVariable::factory()->for($project)->create(['key' => 'API_URL', 'encrypted_value' => 'value-1']);
    serveFromDeploy($project);
    $url = route('api.v1.app.projects.environment-variables.update', [$project, $variable]);

    $this->patchJson($url, ['value' => 'value-2'])->assertJsonPath('data.redeploy_required', true);

    $this->patchJson($url, ['value' => 'value-1'])
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', false);
});

test('deleting a variable and adding it again with the same value clears the redeploy hint', function (): void {
    $project = fingerprintProject();
    $variable = EnvironmentVariable::factory()->for($project)->create(['key' => 'API_URL', 'encrypted_value' => 'value-1', 'is_secret' => false]);
    serveFromDeploy($project);

    $this->deleteJson(route('api.v1.app.projects.environment-variables.destroy', [$project, $variable]))->assertNoContent();

    expect(redeployHint($project))->toBeTrue();

    // `is_secret` differs on purpose: it is not part of what the container gets.
    $this->postJson(route('api.v1.app.projects.environment-variables.store', $project), [
        'key' => 'API_URL', 'value' => 'value-1', 'is_secret' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.redeploy_required', false);
});

// ─── Deployments from before fingerprints ────────────────────────────────────

test('a deployment without a fingerprint falls back to the last deploy-relevant change', function (): void {
    $project = fingerprintProject();
    $legacy = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'created_at' => now()->subHour(),
    ]);
    $project->update(['active_deployment_id' => $legacy->id, 'runtime_status' => RuntimeStatus::Running]);

    expect($legacy->config_fingerprint)->toBeNull()
        ->and(redeployHint($project))->toBeFalse();

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['name' => 'Renamed App'])
        ->assertJsonPath('data.redeploy_required', false);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['build_command' => 'npm run build'])
        ->assertJsonPath('data.redeploy_required', true);

    // Timestamps cannot tell a change made back, so the fallback keeps asking.
    $this->patchJson("/api/v1/app/projects/{$project->id}", ['build_command' => null])
        ->assertJsonPath('data.redeploy_required', true);

    $this->travel(5)->seconds();

    serveFromDeploy($project);

    expect(redeployHint($project))->toBeFalse();
});

// ─── Serialization ───────────────────────────────────────────────────────────

test('fingerprints never appear in a response', function (): void {
    $project = fingerprintProject();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'API_URL', 'encrypted_value' => 'value-1']);
    $deployment = serveFromDeploy($project);
    $project->refresh();

    $secrets = [$project->environment_fingerprint, $deployment->config_fingerprint];

    expect($secrets)->each->not->toBeNull()
        ->and($project->toArray())->not->toHaveKey('environment_fingerprint')
        ->and($deployment->toArray())->not->toHaveKey('config_fingerprint');

    $responses = [
        $this->getJson('/api/v1/app/projects'),
        $this->getJson("/api/v1/app/projects/{$project->id}"),
        $this->getJson("/api/v1/app/projects/{$project->id}/deployments"),
        $this->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}"),
        $this->getJson(route('api.v1.app.projects.environment-variables.index', $project)),
    ];

    foreach ($responses as $response) {
        $response->assertOk()
            ->assertDontSee('environment_fingerprint')
            ->assertDontSee('config_fingerprint');

        foreach ($secrets as $secret) {
            $response->assertDontSee($secret);
        }
    }
});
