<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Events\Deployment\DeploymentEventCreated;
use App\Events\Deployment\DeploymentLogCreated;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Services\Security\ProjectSecretValues;
use App\Services\Security\SecretRedactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const PROJECT_SECRET = 'correct-horse+battery/staple';

/** @return array{AgentNode, Project, Deployment, AgentCommand} */
function redactionContext(): array
{
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'token_hash' => hash_hmac('sha256', 'redact-token', (string) config('app.key')),
    ]);
    $project = Project::factory()->create();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'DB_PW', 'encrypted_value' => PROJECT_SECRET, 'is_secret' => true]);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 1,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    return [$node, $project, $deployment, $command];
}

function redactionHeaders(AgentNode $node): array
{
    return ['Authorization' => 'Bearer redact-token', 'X-Agent-Id' => $node->agent_id];
}

function logLine(string $message): array
{
    return ['logs' => [[
        'stream' => 'stdout',
        'message' => $message,
        'recorded_at' => now()->toIso8601String(),
    ]]];
}

test('a project secret is removed from stored and broadcast logs', function (string $encoded): void {
    Event::fake([DeploymentLogCreated::class]);
    [$node, , $deployment, $command] = redactionContext();

    $this->withHeaders(redactionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", logLine("connecting with {$encoded} now"))
        ->assertOk();

    $stored = DeploymentLog::query()->where('deployment_id', $deployment->id)->sole()->message;

    expect($stored)->toBe('connecting with [REDACTED] now');

    Event::assertDispatched(
        DeploymentLogCreated::class,
        fn (DeploymentLogCreated $event): bool => ! str_contains(json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR), $encoded),
    );
})->with([
    'raw' => [PROJECT_SECRET],
    'base64' => [base64_encode(PROJECT_SECRET)],
    'base64url' => [rtrim(strtr(base64_encode(PROJECT_SECRET), '+/', '-_'), '=')],
    'url encoded' => [rawurlencode(PROJECT_SECRET)],
    'hex' => [bin2hex(PROJECT_SECRET)],
]);

test('a project secret is removed from event messages and metadata', function (): void {
    Event::fake([DeploymentEventCreated::class]);
    [$node, , $deployment, $command] = redactionContext();

    $this->withHeaders(redactionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/events", ['events' => [[
            'type' => 'deployment.note',
            'level' => 'info',
            'message' => 'value '.PROJECT_SECRET,
            'metadata' => ['nested' => ['value' => PROJECT_SECRET]],
            'occurred_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    $event = DeploymentEvent::query()->where('deployment_id', $deployment->id)->sole();

    expect($event->message)->toBe('value [REDACTED]')
        ->and($event->metadata['nested']['value'])->toBe('[REDACTED]');
    Event::assertDispatched(
        DeploymentEventCreated::class,
        fn (DeploymentEventCreated $created): bool => ! str_contains(json_encode($created->broadcastWith(), JSON_THROW_ON_ERROR), PROJECT_SECRET),
    );
});

test('a project secret is removed from the failure detail', function (): void {
    [$node, , $deployment, $command] = redactionContext();

    $this->withHeaders(redactionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", [
            'error_code' => 'runtime_build_failed',
            'error_message' => 'build printed '.PROJECT_SECRET,
        ])
        ->assertNoContent();

    expect($deployment->fresh()->failure_detail)->toBe('build printed [REDACTED]')
        ->and($command->fresh()->error_message)->toBe('build printed [REDACTED]');
});

test('short values, non-secret values, and other projects are left alone', function (): void {
    [$node, $project, $deployment, $command] = redactionContext();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'SHORT', 'encrypted_value' => 'abc', 'is_secret' => true]);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'APP_ENV', 'encrypted_value' => 'production', 'is_secret' => false]);
    $other = Project::factory()->create();
    EnvironmentVariable::factory()->for($other)->create(['key' => 'OTHER', 'encrypted_value' => 'someone-elses-secret', 'is_secret' => true]);

    $message = 'abc running in production with someone-elses-secret';

    $this->withHeaders(redactionHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/logs", logLine($message))
        ->assertOk();

    expect(DeploymentLog::query()->where('deployment_id', $deployment->id)->sole()->message)->toBe($message);
});

test('a retry with the same key still deduplicates after redaction', function (): void {
    [$node, , $deployment, $command] = redactionContext();
    $body = logLine('value '.PROJECT_SECRET);

    foreach ([0, 1] as $duplicates) {
        $this->withHeaders([...redactionHeaders($node), 'Idempotency-Key' => '6f9a1d2e-3b4c-4d5e-8f60-718293a4b5c6'])
            ->postJson("/api/agent/v1/commands/{$command->id}/logs", $body)
            ->assertOk()
            ->assertJsonPath('data.duplicate_count', $duplicates);
    }

    expect(DeploymentLog::query()->where('deployment_id', $deployment->id)->count())->toBe(1);
});

test('a multi-line secret is redacted line by line', function (): void {
    $project = Project::factory()->create();
    EnvironmentVariable::factory()->for($project)->create([
        'key' => 'PEM',
        'encrypted_value' => "-----BEGIN KEY-----\nMIIEvQIBADANBg\nshort\n-----END KEY-----",
        'is_secret' => true,
    ]);

    $values = app(ProjectSecretValues::class)->forProject($project->id);
    $redaction = app(SecretRedactionService::class);

    expect($redaction->redactString('line MIIEvQIBADANBg here', $values))->toBe('line [REDACTED] here')
        ->and($redaction->redactString('short stays', $values))->toBe('short stays');
});

test('the longest value wins when one secret contains another', function (): void {
    $redaction = app(SecretRedactionService::class);

    expect($redaction->redactString('x abcdefgh-ijkl y', ['abcdefgh', 'abcdefgh-ijkl']))->toBe('x [REDACTED] y');
});

test('a command without a project has nothing to look up', function (): void {
    expect(app(ProjectSecretValues::class)->forProject(null))->toBe([]);
});
