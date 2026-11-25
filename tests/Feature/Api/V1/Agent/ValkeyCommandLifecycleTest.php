<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentTrigger;
use App\Enums\ValkeyLockSource;
use App\Enums\ValkeyMode;
use App\Enums\ValkeyStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\ManagedValkey;
use App\Services\Deployment\DeploymentFailureClassifier;
use App\Services\Valkey\ValkeyCommandService;
use App\Support\Attachment\CredentialRotation;
use App\Support\Valkey\ValkeyCommandFailure;
use App\Support\Valkey\ValkeyPasswordWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

/** @return list<string> */
function valkeyAuditActions(ManagedValkey $valkey): array
{
    return AuditEvent::query()->where('subject_id', $valkey->id)->orderBy('id')->pluck('action')->all();
}

test('the Valkey node polls the store commands with the password in plaintext for it only', function (): void {
    $node = valkeyNode();
    $other = valkeyNode();
    $runtime = valkeyNode(capabilities: WORKER_SCHEDULE_CAPABILITIES, reported: false);
    $valkey = ManagedValkey::factory()->inWorkspace(valkeyTeam()['workspace'])->onNode($node)->provisioning()->create();
    $command = issuedValkeyCommand($valkey, AgentCommandType::CreateValkey, AgentCommandStatus::Pending);

    $this->withHeaders(valkeyNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $command->id)
        ->assertJsonPath('data.0.type', 'CreateValkey')
        ->assertJsonPath('data.0.project_id', null)
        ->assertJsonPath('data.0.deployment_id', null)
        ->assertJsonPath('data.0.payload', [
            'valkey_id' => $valkey->id,
            'mode' => 'persistent',
            'maxmemory_mb' => 64,
            'password' => $valkey->password,
        ]);

    $this->withHeaders(valkeyNodeHeaders($other))->getJson('/api/agent/v1/commands')->assertOk()->assertJsonCount(0, 'data');

    // A node without `managed-valkey` never sees Valkey commands, even pinned to it.
    $command->update(['agent_node_id' => $runtime->id]);
    $this->withHeaders(valkeyNodeHeaders($runtime))->getJson('/api/agent/v1/commands')->assertOk()->assertJsonCount(0, 'data');
});

test('the payloads match the agent protocol fixture key for key and type for type', function (AgentCommandType $type): void {
    $node = valkeyNode();
    $valkey = valkeyIn(valkeyTeam()['workspace'], $node, ['status' => $type === AgentCommandType::UnlockValkey ? ValkeyStatus::Locked : ValkeyStatus::Ready]);
    issuedValkeyCommand($valkey, $type, AgentCommandStatus::Pending);
    $fixture = collect(agentCommandFixture('valkey-commands')['data'])->firstWhere('type', $type->value);

    $command = $this->withHeaders(valkeyNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->json('data.0');

    expect($command['project_id'])->toBe($fixture['project_id'])
        ->and($command['deployment_id'])->toBe($fixture['deployment_id'])
        ->and(array_keys($command['payload']))->toBe(array_keys($fixture['payload']))
        ->and(array_map(get_debug_type(...), $command['payload']))->toBe(array_map(get_debug_type(...), $fixture['payload']));

    if (isset($command['payload']['password'])) {
        expect($command['payload']['password'])->toMatch('/^[A-Za-z0-9]{32,128}$/');
    }
})->with([
    AgentCommandType::CreateValkey,
    AgentCommandType::RotateValkeyPassword,
    AgentCommandType::ResizeValkey,
    AgentCommandType::LockValkey,
    AgentCommandType::UnlockValkey,
    AgentCommandType::DeleteValkey,
]);

test('a completed creation stores the network and address and makes the store ready', function (): void {
    $node = valkeyNode();
    $valkey = ManagedValkey::factory()->inWorkspace(valkeyTeam()['workspace'])->onNode($node)->provisioning()->create(['mode' => ValkeyMode::Cache]);
    $command = issuedValkeyCommand($valkey, AgentCommandType::CreateValkey);

    completeValkeyCommand($node, $command, valkeyResultFor($valkey, AgentCommandType::CreateValkey, '10.213.0.34'));

    $valkey->refresh();

    expect($valkey->status)->toBe(ValkeyStatus::Ready)
        ->and($valkey->network)->toBe('sakala-valkey-'.$valkey->id)
        ->and($valkey->host)->toBe('10.213.0.34')
        ->and($valkey->port)->toBe(6379)
        ->and(valkeyAuditActions($valkey))->toBe(['valkey.provisioned']);

    // Completing again (a retried report) changes nothing.
    completeValkeyCommand($node, $command, valkeyResultFor($valkey, AgentCommandType::CreateValkey, '10.213.0.34'));
    expect(valkeyAuditActions($valkey))->toBe(['valkey.provisioned']);
});

test('a creation result without the store\'s own network or an IPv4 address fails the store', function (array $result): void {
    $node = valkeyNode();
    $valkey = ManagedValkey::factory()->inWorkspace(valkeyTeam()['workspace'])->onNode($node)->provisioning()->create();
    $command = issuedValkeyCommand($valkey, AgentCommandType::CreateValkey);

    completeValkeyCommand($node, $command, [...valkeyResultFor($valkey, AgentCommandType::CreateValkey), ...$result]);

    expect($valkey->fresh()->status)->toBe(ValkeyStatus::Failed)
        ->and($valkey->fresh()->host)->toBeNull()
        ->and(valkeyAuditActions($valkey))->toBe(['valkey.invalid_result', 'valkey.provisioning_failed']);
})->with([
    'another network' => [['network' => 'sakala-valkey-0b5c3f9e-1d2a-4e8b-9c7d-6a5f4e3d2c1b']],
    'a host name' => [['endpoint' => ['host' => 'valkey', 'port' => 6379]]],
    'no endpoint' => [['endpoint' => null]],
]);

test('a reconcile on a ready store with a moved address refreshes the attached projects', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node);
    attachValkey($valkey, $project);
    deployLive($project, $team['owner']);
    $command = issuedValkeyCommand($valkey, AgentCommandType::CreateValkey);

    completeValkeyCommand($node, $command, valkeyResultFor($valkey, AgentCommandType::CreateValkey, '10.213.0.66'));

    expect($valkey->fresh()->status)->toBe(ValkeyStatus::Ready)
        ->and($valkey->fresh()->host)->toBe('10.213.0.66')
        ->and($project->fresh()->requiresRedeploy())->toBeTrue()
        ->and(valkeyAuditActions($valkey))->toBe(['valkey.reconciled']);
});

test('a failed or expired creation marks the store failed with the code', function (string $how): void {
    $node = valkeyNode();
    $valkey = ManagedValkey::factory()->inWorkspace(valkeyTeam()['workspace'])->onNode($node)->provisioning()->create();
    $command = issuedValkeyCommand($valkey, AgentCommandType::CreateValkey);

    if ($how === 'fail') {
        failValkeyCommand($node, $command, 'valkey_capacity_exceeded', 'stores would need 448 MiB');
    } else {
        $command->update(['lease_expires_at' => now()->subMinute()]);
        $this->artisan('agent:expire-commands')->assertSuccessful();
    }

    $audit = AuditEvent::query()->where('action', 'valkey.provisioning_failed')->sole();

    expect($valkey->fresh()->status)->toBe(ValkeyStatus::Failed)
        ->and($audit->metadata['error_code'])->toBe($how === 'fail' ? 'valkey_capacity_exceeded' : 'command_lease_expired');
})->with(['fail', 'expire']);

test('the store shows the last failure with an Indonesian summary, and which codes the user can fix', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);
    $command = issuedValkeyCommand($valkey, AgentCommandType::ResizeValkey, sizeMb: 32);
    $valkey->update(['reserved_maxmemory_mb' => 64]);

    failValkeyCommand($node, $command, 'valkey_size_below_usage', 'the persistent store holds 41 MiB');

    $this->actingAs($team['owner'], 'web')
        ->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertOk()
        ->assertJsonPath('data.last_failure.command', 'ResizeValkey')
        ->assertJsonPath('data.last_failure.code', 'valkey_size_below_usage')
        ->assertJsonPath('data.last_failure.user_fixable', true)
        ->assertJsonPath('data.last_failure.summary', fn (string $summary): bool => str_contains($summary, 'ukuran'))
        ->assertJsonPath('data.maxmemory_mb', 64)
        ->assertJsonPath('data.pending_maxmemory_mb', null);

    expect($valkey->fresh()->reserved_maxmemory_mb)->toBe(64);
});

test('a lock disables the store, an unlock enables it again, and each records its source', function (): void {
    $node = valkeyNode();
    $valkey = valkeyIn(valkeyTeam()['workspace'], $node);

    completeValkeyCommand($node, issuedValkeyCommand($valkey, AgentCommandType::LockValkey), valkeyResultFor($valkey, AgentCommandType::LockValkey));

    expect($valkey->fresh()->status)->toBe(ValkeyStatus::Locked)
        ->and($valkey->fresh()->lock_source)->toBe(ValkeyLockSource::Admin);

    completeValkeyCommand($node, issuedValkeyCommand($valkey, AgentCommandType::UnlockValkey), valkeyResultFor($valkey, AgentCommandType::UnlockValkey));

    expect($valkey->fresh()->status)->toBe(ValkeyStatus::Ready)
        ->and($valkey->fresh()->lock_source)->toBeNull()
        ->and(valkeyAuditActions($valkey))->toBe(['valkey.locked', 'valkey.unlocked']);
});

test('a completed rotation stores the new password, asks attached projects for a redeploy, and redeploys them when asked', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node);
    attachValkey($valkey, $project);
    deployLive($project, $team['owner']);

    $this->actingAs($team['owner'], 'web')
        ->postJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/rotate-password"), ['redeploy_attached' => true])
        ->assertAccepted();

    $command = valkeyCommand($valkey, AgentCommandType::RotateValkeyPassword);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    expect(ValkeyPasswordWindow::isOpen($valkey->fresh()))->toBeTrue();

    completeValkeyCommand($node, $command, valkeyResultFor($valkey, AgentCommandType::RotateValkeyPassword));

    $new = app(ValkeyCommandService::class)->passwordOf($command->fresh());
    $redeploy = Deployment::query()->where('trigger', DeploymentTrigger::ValkeyPasswordRotated)->sole();

    expect($valkey->fresh()->password)->toBe($new)
        ->and(ValkeyPasswordWindow::isOpen($valkey->fresh()))->toBeFalse()
        ->and($redeploy->idempotency_key)->toBe("valkey-password-rotation:{$command->id}")
        ->and(deployPayloadOf($redeploy)['valkey']['network'])->toBe($valkey->network)
        ->and(CredentialRotation::redeploysQueued($command->fresh()))->toBeTrue()
        ->and(valkeyAuditActions($valkey))->toContain('valkey.password_rotated', 'valkey.password_rotation_redeploys_queued');

    $this->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertJsonPath('data.password_rotation.status', 'completed')
        ->assertJsonPath('data.password_rotation.redeploys.0.project_id', $project->id)
        ->assertJsonPath('data.password_rotation.redeploys.0.deployment_id', $redeploy->id);
});

test('a rotation the node could not confirm is sent again with the same password, and ends unknown after the last attempt', function (): void {
    $node = valkeyNode();
    $team = valkeyTeam();
    $valkey = valkeyIn($team['workspace'], $node);
    $old = (string) $valkey->password;
    $first = issuedValkeyCommand($valkey, AgentCommandType::RotateValkeyPassword);

    failValkeyCommand($node, $first, 'valkey_unavailable', 'the store did not answer ACL LOAD');

    $resend = valkeyCommand($valkey, AgentCommandType::RotateValkeyPassword);

    expect($resend->id)->not->toBe($first->id)
        ->and($resend->payload)->toBe($first->payload)
        ->and(CredentialRotation::attempt($resend))->toBe(2)
        ->and($resend->available_at->greaterThan(now()))->toBeTrue()
        ->and(ValkeyPasswordWindow::isOpen($valkey->fresh()))->toBeTrue()
        ->and($valkey->fresh()->password)->toBe($old);

    // The last allowed attempt fails: unknown, and deploys stay refused.
    $resend->update([
        'status' => AgentCommandStatus::Running,
        'claimed_at' => now(),
        'request_context' => [...$resend->request_context, CredentialRotation::ATTEMPT_KEY => CredentialRotation::MAX_UNCERTAIN_ATTEMPTS],
    ]);
    failValkeyCommand($node, $resend, 'valkey_unavailable');

    $this->actingAs($team['owner'], 'web')
        ->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}"))
        ->assertJsonPath('data.password_rotation.status', 'unknown');

    $this->getJson(valkeysUrl($team['workspace']->id, "/{$valkey->id}/credentials"))
        ->assertConflict()
        ->assertJsonPath('code', 'VALKEY_PASSWORD_ROTATING');

    // valkeys:reconcile puts the stored password back and closes the window.
    $this->artisan('valkeys:reconcile', ['--valkey' => $valkey->id])->assertSuccessful();
    $create = valkeyCommand($valkey, AgentCommandType::CreateValkey);
    $create->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    completeValkeyCommand($node, $create, valkeyResultFor($valkey, AgentCommandType::CreateValkey));

    expect(ValkeyPasswordWindow::isOpen($valkey->fresh()))->toBeFalse()
        ->and(app(ValkeyCommandService::class)->passwordOf($create))->toBe($old);
});

test('a rotation that failed for sure keeps the old password and is not resent', function (): void {
    $node = valkeyNode();
    $valkey = valkeyIn(valkeyTeam()['workspace'], $node);
    $command = issuedValkeyCommand($valkey, AgentCommandType::RotateValkeyPassword);

    failValkeyCommand($node, $command, 'valkey_not_found');

    expect(AgentCommand::query()->where('type', AgentCommandType::RotateValkeyPassword)->count())->toBe(1)
        ->and(ValkeyPasswordWindow::isOpen($valkey->fresh()))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'valkey.password_rotation_failed')->sole()->metadata['outcome_unknown'])->toBeFalse();
});

test('agent failure fixtures map to the API handling of every code', function (array $failure): void {
    $node = valkeyNode();
    $type = AgentCommandType::from($failure['type']);

    if (! $type->isValkey()) {
        // valkey_network_unavailable of DeployProject and RunJob: a known code with an Indonesian message.
        expect(app(DeploymentFailureClassifier::class)->classify($failure['body']['error_code'])->category->value)->not->toBe('unknown')
            ->and(ValkeyCommandFailure::NETWORK_UNAVAILABLE)->toBe($failure['body']['error_code']);

        return;
    }

    $valkey = valkeyIn(valkeyTeam()['workspace'], $node, ['status' => match ($type) {
        AgentCommandType::CreateValkey => ValkeyStatus::Provisioning,
        AgentCommandType::DeleteValkey => ValkeyStatus::Deleting,
        AgentCommandType::UnlockValkey => ValkeyStatus::Locked,
        default => ValkeyStatus::Ready,
    }]);
    $command = issuedValkeyCommand($valkey, $type);

    failValkeyCommand($node, $command, $failure['body']['error_code'], $failure['body']['error_message']);

    $command->refresh();

    expect($command->status)->toBe(AgentCommandStatus::Failed)
        ->and($command->error_code)->toBe($failure['body']['error_code'])
        ->and(ValkeyCommandFailure::present($command)['summary'])->not->toBe('');

    match ($type) {
        AgentCommandType::CreateValkey => expect($valkey->fresh()->status)->toBe(ValkeyStatus::Failed),
        AgentCommandType::DeleteValkey => expect($valkey->fresh()->status)->toBe(ValkeyStatus::Deleting),
        AgentCommandType::RotateValkeyPassword => expect(AgentCommand::query()->where('type', $type)->count())
            ->toBe($failure['body']['error_code'] === 'valkey_unavailable' ? 2 : 1),
        default => expect($valkey->fresh()->status)->not->toBe(ValkeyStatus::Failed),
    };
})->with(array_map(
    static fn (array $failure): array => [$failure],
    json_decode((string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/agent-protocol-v4/failures/valkey-failures.json'), true, flags: JSON_THROW_ON_ERROR),
));
