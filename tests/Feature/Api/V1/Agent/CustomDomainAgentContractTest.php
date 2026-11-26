<?php

declare(strict_types=1);

use App\Actions\Project\CreateWakeProjectCommandAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\SleepPage;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\CustomDomain\CustomDomainRouteSync;
use App\Support\CustomDomain\CustomDomainAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/*
 * The custom domain part of the agent contract (ADR-024), replayed from the
 * fixtures copied from sakala-agent main b19b9e0 (tests/Fixtures/agent-protocol-v4/README.md).
 */

beforeEach(function (): void {
    enableCustomDomains();
    $this->node = databaseNode(overrides: ['capabilities' => CUSTOM_DOMAIN_NODE_CAPABILITIES]);
    $this->project = Project::factory()->create();
    $this->deployment = Deployment::factory()->for($this->project)->create([
        'agent_node_id' => $this->node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $this->project->update(['active_deployment_id' => $this->deployment->id]);
});

/** Verified claims of the project for the hostnames, in the given order. */
function verifiedDomains(Project $project, array $hostnames): void
{
    foreach ($hostnames as $hostname) {
        projectDomain($project, ['hostname' => $hostname], verified: true);
    }
}

/**
 * Stores the fixture command for the test's project the way the API stores
 * it: without `custom_domains`, which is added on poll, and with the
 * environment encrypted.
 */
function storeCustomDomainFixtureCommand(object $test, string $name): array
{
    $fixture = customDomainFixture("commands/{$name}.json");
    $stored = $fixture['payload'];
    unset($stored['custom_domains']);

    if (isset($stored['environment'])) {
        $stored['environment'] = array_map(static fn (string $value): string => Crypt::encryptString($value), $stored['environment']);
    }

    $command = AgentCommand::factory()->create([
        'type' => AgentCommandType::from($fixture['type']),
        'status' => AgentCommandStatus::Pending,
        'project_id' => $test->project->id,
        'deployment_id' => $test->deployment->id,
        'agent_node_id' => $test->node->id,
        'payload' => $stored,
        'expires_at' => null,
    ]);

    return [$command, $fixture];
}

function failAsNode(AgentNode $node, AgentCommand $command, array $body): void
{
    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", $body)
        ->assertNoContent();
}

// ─── custom_domains on the route-writing commands ────────────────────────────

test('DeployProject and WakeProject carry the verified list on poll, as in the agent fixtures', function (string $name): void {
    [$command, $fixture] = storeCustomDomainFixtureCommand($this, $name);
    verifiedDomains($this->project, $fixture['payload']['custom_domains']);
    projectDomain($this->project, ['hostname' => 'pending.contoh.com']);

    $payload = polledCommand($this->node, $command)['payload'];
    $expected = $fixture['payload'];
    $domains = $expected['custom_domains'];
    sort($domains);
    unset($expected['custom_domains'], $payload['custom_domains']);

    expect(polledCommand($this->node, $command)['payload']['custom_domains'])->toBe($domains)
        ->and($payload)->toBe($expected);
})->with(['deploy-project-custom-domains', 'wake-project-custom-domains']);

test('RestartProject and SleepProject carry the list too; a sleep keeps its page', function (): void {
    verifiedDomains($this->project, ['www.contoh.com']);

    $restart = AgentCommand::factory()->create([
        'type' => AgentCommandType::RestartProject, 'status' => AgentCommandStatus::Pending,
        'project_id' => $this->project->id, 'deployment_id' => $this->deployment->id, 'agent_node_id' => $this->node->id, 'payload' => [], 'expires_at' => null,
    ]);
    $sleep = AgentCommand::factory()->create([
        'type' => AgentCommandType::SleepProject, 'status' => AgentCommandStatus::Pending,
        'project_id' => $this->project->id, 'deployment_id' => $this->deployment->id, 'agent_node_id' => $this->node->id,
        'payload' => ['page' => SleepPage::Unavailable->value], 'expires_at' => null,
    ]);

    expect(polledCommand($this->node, $restart)['payload'])->toBe(['custom_domains' => ['www.contoh.com']])
        ->and(polledCommand($this->node, $sleep)['payload'])->toBe(['page' => 'unavailable', 'custom_domains' => ['www.contoh.com']]);
});

test('a project without verified domains sends an empty list, which clears the route', function (): void {
    $wake = app(CreateWakeProjectCommandAction::class)->handle($this->deployment, 'test', 'test');

    expect(polledCommand($this->node, $wake)['payload'])->toBe(['custom_domains' => []]);
});

test('the list is read when the node polls, not when the command was created', function (): void {
    $wake = app(CreateWakeProjectCommandAction::class)->handle($this->deployment, 'test', 'test');
    verifiedDomains($this->project, ['later.contoh.com']);

    expect(polledCommand($this->node, $wake)['payload'])->toBe(['custom_domains' => ['later.contoh.com']]);
});

test('nothing about custom domains reaches a node without the capability, or any node while the feature is off', function (bool $enabled, array $capabilities): void {
    enableCustomDomains($enabled);
    $this->node->update(['capabilities' => $capabilities]);
    verifiedDomains($this->project, ['www.contoh.com']);
    [$deploy, $fixture] = storeCustomDomainFixtureCommand($this, 'deploy-project-custom-domains');
    $wake = app(CreateWakeProjectCommandAction::class)->handle($this->deployment, 'test', 'test');

    $expected = $fixture['payload'];
    unset($expected['custom_domains']);

    expect(polledCommand($this->node, $deploy)['payload'])->toBe($expected)
        ->and(polledCommand($this->node, $wake)['payload'])->toBe([]);

    $response = test()->withHeaders(databaseNodeHeaders($this->node))->getJson('/api/agent/v1/commands');
    expect($response->getContent())->toContain('"payload":{}');
})->with([
    'feature off' => [false, CUSTOM_DOMAIN_NODE_CAPABILITIES],
    'older agent' => [true, ROLLBACK_CAPABILITIES],
]);

// ─── UpdateProjectDomains ────────────────────────────────────────────────────

test('UpdateProjectDomains reaches the node in the fixture\'s shape and completes with its result', function (): void {
    $fixture = customDomainFixture('commands/update-project-domains.json');
    verifiedDomains($this->project, $fixture['command']['payload']['custom_domains']);

    $command = app(CustomDomainRouteSync::class)->sync($this->project, 'test');
    $polled = polledCommand($this->node, $command);

    expect(array_keys($polled))->toBe(array_keys($fixture['command']))
        ->and($polled['type'])->toBe($fixture['command']['type'])
        ->and($polled['status'])->toBe('Pending')
        ->and($polled['project_id'])->toBe($this->project->id)
        ->and($polled['deployment_id'])->toBe($this->deployment->id)
        // Sorted, as the agent reports them back in its result.
        ->and($polled['payload'])->toBe(['custom_domains' => $fixture['complete']['result']['custom_domains']])
        ->and(array_keys($polled['payload']))->toBe(array_keys($fixture['command']['payload']));

    $this->withHeaders(databaseNodeHeaders($this->node))->postJson("/api/agent/v1/commands/{$command->id}/claim")->assertOk();
    $this->postJson("/api/agent/v1/commands/{$command->id}/complete", $fixture['complete'])->assertNoContent();

    expect($command->fresh())
        ->status->toBe(AgentCommandStatus::Succeeded)
        ->result->toBe($fixture['complete']['result']);
});

test('UpdateProjectDomains while the feature is off carries an empty list, never a missing field', function (): void {
    verifiedDomains($this->project, ['www.contoh.com']);
    $command = app(CustomDomainRouteSync::class)->sync($this->project, 'test');
    enableCustomDomains(false);

    expect(polledCommand($this->node, $command)['payload'])->toBe(['custom_domains' => []]);
});

test('UpdateProjectDomains is never offered to a node without the capability', function (): void {
    $older = databaseNode(overrides: ['capabilities' => ROLLBACK_CAPABILITIES]);
    AgentCommand::factory()->create([
        'type' => AgentCommandType::UpdateProjectDomains, 'status' => AgentCommandStatus::Pending,
        'project_id' => $this->project->id, 'deployment_id' => $this->deployment->id, 'agent_node_id' => $older->id,
        'payload' => ['custom_domains' => []], 'expires_at' => null,
    ]);

    expect(pollAs($older))->toBe([]);
});

test('UpdateProjectDomains is not held back by a suspension: it starts nothing', function (): void {
    expect(AgentCommandType::UpdateProjectDomains->isBlockedForSuspendedProject())->toBeFalse()
        ->and(AgentCommandType::UpdateProjectDomains->isProjectLifecycle())->toBeFalse()
        ->and(AgentCommandType::UpdateProjectDomains->isPinnedAtCreation())->toBeTrue()
        ->and(AgentCommandType::UpdateProjectDomains->requiredCapabilities())->toBe([AgentCommandType::CUSTOM_DOMAINS_CAPABILITY]);
});

// ─── Failure codes ───────────────────────────────────────────────────────────

test('every custom domain failure body of the agent is accepted', function (): void {
    verifiedDomains($this->project, ['www.contoh.com']);

    foreach (customDomainFixture('failures/custom-domain-failures.json') as $failure) {
        $type = AgentCommandType::from($failure['type']);
        $deployment = $type === AgentCommandType::DeployProject
            ? Deployment::factory()->for($this->project)->create(['agent_node_id' => $this->node->id, 'status' => DeploymentStatus::Building, 'sequence' => 10 + AgentCommand::query()->count()])
            : $this->deployment;

        $command = claimedByNode(AgentCommand::factory()->create([
            'type' => $type, 'status' => AgentCommandStatus::Pending,
            'project_id' => $this->project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $this->node->id,
            'payload' => $type === AgentCommandType::UpdateProjectDomains ? ['custom_domains' => ['www.contoh.com']] : [], 'expires_at' => null,
            'request_context' => ['attempt' => CustomDomainRouteSync::MAX_TAKEN_RETRIES + 1],
        ]));

        failAsNode($this->node, $command, $failure['body']);

        expect($command->fresh())
            ->status->toBe(AgentCommandStatus::Failed)
            ->error_code->toBe($failure['body']['error_code']);
    }
});

test('a deployment failing on a custom domain gets a failure explanation in Indonesian', function (string $code, string $summary): void {
    $deployment = Deployment::factory()->for($this->project)->create(['agent_node_id' => $this->node->id, 'status' => DeploymentStatus::Building, 'sequence' => 2]);
    $command = claimedByNode(AgentCommand::factory()->create([
        'type' => AgentCommandType::DeployProject, 'status' => AgentCommandStatus::Pending,
        'project_id' => $this->project->id, 'deployment_id' => $deployment->id, 'agent_node_id' => $this->node->id, 'payload' => [], 'expires_at' => null,
    ]));

    failAsNode($this->node, $command, ['error_code' => $code, 'error_message' => 'x']);

    expect($deployment->fresh())
        ->status->toBe(DeploymentStatus::Failed)
        ->failure_code->toBe($code)
        ->failure_summary->toContain($summary);
})->with([
    [CustomDomainRouteSync::ERROR_INVALID, 'menolak salah satu custom domain'],
    [CustomDomainRouteSync::ERROR_TAKEN, 'masih tercatat di proyek lain'],
]);

test('runtime_custom_domain_taken on UpdateProjectDomains is sent again a minute later, a bounded number of times', function (): void {
    verifiedDomains($this->project, ['www.contoh.com']);
    $command = claimedByNode(app(CustomDomainRouteSync::class)->sync($this->project, 'test'));
    $taken = collect(customDomainFixture('failures/custom-domain-failures.json'))->firstWhere('body.error_code', CustomDomainRouteSync::ERROR_TAKEN)['body'];

    failAsNode($this->node, $command, $taken);

    $retry = updateDomainCommands($this->project)->last();

    expect($retry->id)->not->toBe($command->id)
        ->and($retry->status)->toBe(AgentCommandStatus::Pending)
        ->and($retry->request_context['attempt'])->toBe(2)
        ->and($retry->available_at->toDateTimeString())->toBe(now()->addSeconds(CustomDomainRouteSync::TAKEN_RETRY_DELAY_SECONDS)->toDateTimeString())
        ->and(AuditEvent::query()->where('action', CustomDomainAudit::ROUTE_UPDATE_FAILED)->count())->toBe(1);

    $retry->update(['request_context' => ['attempt' => CustomDomainRouteSync::MAX_TAKEN_RETRIES + 1]]);
    failAsNode($this->node, claimedByNode($retry), $taken);

    expect(updateDomainCommands($this->project))->toHaveCount(2);
});

test('an UpdateProjectDomains whose lease ran out is sent again', function (): void {
    verifiedDomains($this->project, ['www.contoh.com']);
    $command = claimedByNode(app(CustomDomainRouteSync::class)->sync($this->project, 'test'));
    $command->update(['lease_expires_at' => now()->subMinute()]);

    $this->artisan('agent:expire-commands')->assertSuccessful();

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Expired)
        ->and(updateDomainCommands($this->project)->where('status', AgentCommandStatus::Pending))->toHaveCount(1);
});
