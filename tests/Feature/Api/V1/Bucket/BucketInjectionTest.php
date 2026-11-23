<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Enums\DeploymentStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\EnvironmentVariable;
use App\Models\ManagedBucket;
use App\Models\Project;
use App\Services\Bucket\BucketConnectionEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '7a1f0e2d3b4a5968778695a4b3c2d1e0f9a8b7c6', 'commit' => ['message' => 'feat: bucket']],
        ], 200),
    ]);
});

/** One node that builds and runs projects and also hosts the object storage (a single-node install). */
function bucketRuntimeNode(bool $endpoint = true): AgentNode
{
    return storageNode(endpoint: $endpoint, overrides: [
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build', 'caddy-file-routing', AgentCommandType::MANAGED_S3_CAPABILITY],
    ]);
}

/** @return array{Project, ManagedBucket, array<string, mixed>} */
function projectWithBucket(bool $endpoint = true, array $bucket = []): array
{
    $node = bucketRuntimeNode($endpoint);
    $team = bucketTeam();
    $managed = bucketIn($team['workspace'], $node, $bucket);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['branch' => 'main']);
    attachBucket($managed, $project);

    return [$project, $managed, $team];
}

/** @return array<string, string> */
function bucketDeployedEnvironment(AgentCommand $command): array
{
    return array_map(static fn (string $value): string => Crypt::decryptString($value), $command->payload['environment']);
}

test('the names of the bucket variables are fixed, with the S3-only endpoint and never the global one', function (): void {
    expect(BucketConnectionEnvironment::names())->toBe([
        'AWS_ENDPOINT_URL_S3',
        'AWS_ENDPOINT',
        'AWS_USE_PATH_STYLE_ENDPOINT',
        'AWS_DEFAULT_REGION',
        'AWS_REGION',
        'AWS_BUCKET',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
    ])->not->toContain('AWS_ENDPOINT_URL', 'FILESYSTEM_DISK', 'AWS_URL');
});

test('a deployment of a project with an attached bucket receives exactly the bucket variables, in order', function (): void {
    [$project, $bucket, $team] = projectWithBucket();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'APP_ENV', 'encrypted_value' => 'production', 'is_secret' => false]);
    $secret = (string) $bucket->secret_access_key;

    $this->actingAs($team['member'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();

    $command = AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole();

    expect(bucketDeployedEnvironment($command))->toBe([
        'APP_ENV' => 'production',
        'AWS_ENDPOINT_URL_S3' => STORAGE_NODE_ENDPOINT,
        'AWS_ENDPOINT' => STORAGE_NODE_ENDPOINT,
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        'AWS_DEFAULT_REGION' => STORAGE_NODE_REGION,
        'AWS_REGION' => STORAGE_NODE_REGION,
        'AWS_BUCKET' => $bucket->s3_bucket,
        'AWS_ACCESS_KEY_ID' => $bucket->access_key_id,
        'AWS_SECRET_ACCESS_KEY' => $secret,
    ])
        ->and(EnvironmentVariable::query()->where('project_id', $project->id)->pluck('key')->all())->toBe(['APP_ENV'])
        ->and(json_encode($command->payload))->not->toContain($secret);
});

test('a project variable with the same name wins, and the project shows which ones', function (): void {
    [$project, $bucket, $team] = projectWithBucket();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'AWS_DEFAULT_REGION', 'encrypted_value' => 'ap-southeast-3', 'is_secret' => false]);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'AWS_SECRET_ACCESS_KEY', 'encrypted_value' => 'my-own-secret-value', 'is_secret' => true]);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.buckets.0.id', $bucket->id)
        ->assertJsonPath('data.buckets.0.status', 'ready')
        ->assertJsonPath('data.buckets.0.overridden_variables', ['AWS_DEFAULT_REGION', 'AWS_SECRET_ACCESS_KEY'])
        ->assertJsonPath('data.buckets.0.variables', [
            'AWS_ENDPOINT_URL_S3', 'AWS_ENDPOINT', 'AWS_USE_PATH_STYLE_ENDPOINT', 'AWS_REGION', 'AWS_BUCKET', 'AWS_ACCESS_KEY_ID',
        ]);

    $this->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated();
    $environment = bucketDeployedEnvironment(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole());

    expect($environment['AWS_DEFAULT_REGION'])->toBe('ap-southeast-3')
        ->and($environment['AWS_SECRET_ACCESS_KEY'])->toBe('my-own-secret-value')
        ->and($environment['AWS_REGION'])->toBe(STORAGE_NODE_REGION);
});

test('bucket and database variables reach the same deployment side by side', function (): void {
    [$project, , $team] = projectWithBucket();
    attachDatabase(databaseIn($team['workspace'], databaseNode()), $project);

    $this->actingAs($team['owner'], 'web')->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated();

    expect(array_keys(bucketDeployedEnvironment(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole())))->toBe([
        'DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        ...BucketConnectionEnvironment::names(),
    ]);
});

test('a project without a bucket shows an empty list and gets no bucket variables', function (): void {
    bucketRuntimeNode();
    $team = bucketTeam();
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['branch' => 'main']);

    $this->actingAs($team['owner'], 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.buckets', []);
    $this->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated();

    expect(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole()->payload['environment'])->toBe([]);
});

test('a deployment is refused while the attached bucket is not created yet or failed', function (BucketStatus $status): void {
    [$project, , $team] = projectWithBucket(bucket: ['status' => $status]);

    $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'BUCKET_NOT_READY')
        ->assertJsonPath('status', $status->value);

    expect(Deployment::query()->count())->toBe(0);
})->with([BucketStatus::Provisioning, BucketStatus::Failed]);

test('without the storage node endpoint the deployment is refused, and nothing is queued', function (): void {
    [$project, $bucket, $team] = projectWithBucket(endpoint: false);

    $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertExactJson([
            'message' => "Server penyimpanan untuk bucket {$bucket->name} belum melaporkan alamatnya, jadi proyek ini belum bisa di-deploy dengan bucket terpasang. Coba lagi beberapa saat lagi, atau lepas bucket-nya dulu.",
            'code' => 'BUCKET_ENDPOINT_UNAVAILABLE',
            'bucket' => ['id' => $bucket->id, 'name' => $bucket->name],
        ]);

    expect(Deployment::query()->count())->toBe(0)
        ->and(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->count())->toBe(0);
});

test('a locked bucket still injects its variables: the app gets AccessDenied, not a missing config', function (): void {
    [$project, $bucket, $team] = projectWithBucket(bucket: ['status' => BucketStatus::Locked]);

    $this->actingAs($team['owner'], 'web')->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])->assertCreated();

    expect(bucketDeployedEnvironment(AgentCommand::query()->where('type', AgentCommandType::DeployProject)->sole())['AWS_BUCKET'])->toBe($bucket->s3_bucket);
});

test('the deployment stores the fingerprint with the bucket, so detaching asks for a redeploy', function (): void {
    [$project, $bucket, $team] = projectWithBucket();

    $deploymentId = $this->actingAs($team['owner'], 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->json('data.id');

    $deployment = Deployment::query()->findOrFail($deploymentId);
    $deployment->update(['status' => DeploymentStatus::Succeeded]);
    $project->update(['active_deployment_id' => $deployment->id]);

    expect($project->fresh()?->requiresRedeploy())->toBeFalse();

    $this->deleteJson("/api/v1/app/workspaces/{$bucket->workspace_id}/buckets/{$bucket->id}/attachments/{$project->id}")->assertNoContent();

    $this->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.redeploy_required', true);
});

test('the bucket secret never reaches stored deployment logs', function (string $encoding): void {
    [$project, $bucket] = projectWithBucket();
    $node = $bucket->node;
    $deployment = Deployment::factory()->for($project)->create(['agent_node_id' => $node->id, 'status' => DeploymentStatus::Building, 'sequence' => 1]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);
    $value = match ($encoding) {
        'plain' => (string) $bucket->secret_access_key,
        'base64' => base64_encode((string) $bucket->secret_access_key),
        'env line' => 'AWS_SECRET_ACCESS_KEY=whatever-else',
    };

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [[
            'stream' => 'stdout',
            'message' => "signing with {$value} now",
            'recorded_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    expect(DeploymentLog::query()->where('deployment_id', $deployment->id)->sole()->message)
        ->not->toContain((string) $bucket->secret_access_key)
        ->not->toContain('whatever-else')
        ->toContain('[REDACTED]');
})->with(['plain', 'base64', 'env line']);
