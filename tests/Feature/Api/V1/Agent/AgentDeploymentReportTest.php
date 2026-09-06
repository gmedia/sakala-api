<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentEventLevel;
use App\Enums\LogStream;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{agent: AgentNode, command: AgentCommand, deployment: Deployment, token: string} */
function reportContext(array $commandOverrides = []): array
{
    $token = 'report-agent-token';
    $agent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
    $project = Project::factory()->create();
    $deployment = Deployment::factory()->for($project)->create(['sequence' => 1]);
    $command = AgentCommand::factory()->create(array_merge([
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
    ], $commandOverrides));

    return compact('agent', 'command', 'deployment', 'token');
}

/** @return array<string, string> */
function reportHeaders(AgentNode $agent, string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

function eventReportBody(string $message = 'Deployment started.'): array
{
    return [
        'level' => DeploymentEventLevel::Info->value,
        'type' => 'deployment.runtime.ready',
        'message' => $message,
        'metadata' => ['component' => 'runtime'],
        'occurred_at' => '2026-09-07T10:00:00Z',
    ];
}

function logReportBody(string $message = 'Listening on port 8080'): array
{
    return [
        'stream' => LogStream::System->value,
        'message' => $message,
        'recorded_at' => '2026-09-07T10:00:00Z',
    ];
}

test('claiming agent can append a redacted event and receives an acknowledgement', function (): void {
    $context = reportContext();

    $response = $this->withHeaders(reportHeaders($context['agent'], $context['token']))
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/events", [
            ...eventReportBody('password="super-secret" {"api_key":"another-secret"}'),
            'metadata' => ['authorization' => 'Bearer secret-token'],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => ['accepted_count', 'duplicate_count', 'first_sequence', 'last_sequence'],
        ])
        ->assertJsonPath('data.accepted_count', 1)
        ->assertJsonPath('data.duplicate_count', 0)
        ->assertJsonPath('data.first_sequence', 1)
        ->assertJsonPath('data.last_sequence', 1);

    $event = DeploymentEvent::query()->firstOrFail();

    expect($event->deployment_id)->toBe($context['deployment']->id)
        ->and($event->agent_command_id)->toBe($context['command']->id)
        ->and($event->message)->toContain('[REDACTED]')
        ->and($event->message)->not->toContain('super-secret')
        ->and($event->message)->not->toContain('another-secret')
        ->and($event->metadata['authorization'])->toBe('Bearer [REDACTED]');
});

test('claiming agent can append logs and sequences follow request order', function (): void {
    $context = reportContext();

    $response = $this->withHeaders(reportHeaders($context['agent'], $context['token']))
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/logs", [
            'logs' => [
                logReportBody('second line'),
                [...logReportBody('first line'), 'recorded_at' => '2026-09-07T09:00:00Z'],
            ],
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.accepted_count', 2)
        ->assertJsonPath('data.duplicate_count', 0)
        ->assertJsonPath('data.first_sequence', 1)
        ->assertJsonPath('data.last_sequence', 2);

    expect(DeploymentLog::query()->orderBy('sequence')->pluck('message')->all())
        ->toBe(['second line', 'first line']);
});

test('a report retry is idempotent and a changed payload conflicts', function (): void {
    $context = reportContext();
    $headers = reportHeaders($context['agent'], $context['token']);
    $headers['Idempotency-Key'] = 'event-retry-1';
    $url = "/api/agent/v1/commands/{$context['command']->id}/events";

    $this->withHeaders($headers)
        ->postJson($url, eventReportBody())
        ->assertJsonPath('data.duplicate_count', 0);

    $this->withHeaders($headers)
        ->postJson($url, eventReportBody())
        ->assertSuccessful()
        ->assertJsonPath('data.accepted_count', 1)
        ->assertJsonPath('data.duplicate_count', 1)
        ->assertJsonPath('data.first_sequence', 1)
        ->assertJsonPath('data.last_sequence', 1);

    $this->withHeaders($headers)
        ->postJson($url, eventReportBody('a different event'))
        ->assertStatus(409);

    expect(DeploymentEvent::query()->count())->toBe(1);
});

test('reports without an idempotency header deduplicate identical payloads', function (): void {
    $context = reportContext();
    $url = "/api/agent/v1/commands/{$context['command']->id}/logs";
    $headers = reportHeaders($context['agent'], $context['token']);

    $this->withHeaders($headers)->postJson($url, logReportBody())->assertSuccessful();
    $this->withHeaders($headers)
        ->postJson($url, logReportBody())
        ->assertSuccessful()
        ->assertJsonPath('data.duplicate_count', 1);

    expect(DeploymentLog::query()->count())->toBe(1);
});

test('an agent cannot report for a command claimed by another agent', function (): void {
    $context = reportContext();
    $otherToken = 'other-agent-token';
    $otherAgent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'token_hash' => hash_hmac('sha256', $otherToken, (string) config('app.key')),
    ]);

    $this->withHeaders(reportHeaders($otherAgent, $otherToken))
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/events", eventReportBody())
        ->assertStatus(409);

    expect(DeploymentEvent::query()->count())->toBe(0);
});

test('a command with inconsistent project and deployment binding cannot report', function (): void {
    $context = reportContext([
        'project_id' => Project::factory(),
    ]);

    $this->withHeaders(reportHeaders($context['agent'], $context['token']))
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/logs", logReportBody())
        ->assertStatus(409);

    expect(DeploymentLog::query()->count())->toBe(0);
});

test('report validation rejects invalid protocol values and multiline logs', function (): void {
    $context = reportContext();
    $headers = reportHeaders($context['agent'], $context['token']);

    $this->withHeaders($headers)
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/events", [
            ...eventReportBody(),
            'level' => 'critical',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['events.0.level']);

    $this->withHeaders($headers)
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/logs", logReportBody("line one\nline two"))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['logs.0.message']);
});

test('report limits reject oversized batches, command snapshots, and request bodies', function (): void {
    $context = reportContext([
        'payload' => [
            'log_bounds' => [
                'max_line_length' => 4,
                'max_batch_lines' => 1,
                'max_total_bytes' => 1000,
            ],
        ],
    ]);
    $headers = reportHeaders($context['agent'], $context['token']);
    $url = "/api/agent/v1/commands/{$context['command']->id}/logs";

    $this->withHeaders($headers)
        ->postJson($url, ['logs' => [logReportBody('one'), logReportBody('two')]])
        ->assertStatus(422);

    $this->withHeaders($headers)
        ->postJson($url, logReportBody('too long'))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['logs.0.message']);

    config()->set('sakala.pilot_limits.log_bounds.max_total_bytes', 10);

    $this->withHeaders($headers)
        ->postJson($url, logReportBody('payload is larger than ten bytes'))
        ->assertStatus(413);
});

test('terminal commands cannot append reports', function (): void {
    $context = reportContext(['status' => AgentCommandStatus::Succeeded]);

    $this->withHeaders(reportHeaders($context['agent'], $context['token']))
        ->postJson("/api/agent/v1/commands/{$context['command']->id}/events", eventReportBody())
        ->assertStatus(409);
});

test('report endpoints require agent authentication', function (): void {
    $context = reportContext();

    $this->postJson("/api/agent/v1/commands/{$context['command']->id}/events", eventReportBody())
        ->assertUnauthorized();
});
