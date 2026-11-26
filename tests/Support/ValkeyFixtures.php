<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ValkeyLockSource;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Models\ValkeyAttachment;
use App\Models\Workspace;
use App\Services\Valkey\ValkeyCommandService;
use Database\Factories\ManagedValkeyFactory;

/** Budget a factory Valkey node reports (`SAKALA_VALKEY_TOTAL_MEMORY_MB`), the production value. */
const VALKEY_NODE_CAPACITY_MB = 384;

/** What a docker-driver agent with the Valkey role advertises: a runtime node that also runs stores. */
const VALKEY_NODE_CAPABILITIES = [...WORKER_SCHEDULE_CAPABILITIES, AgentCommandType::MANAGED_VALKEY_CAPABILITY];

function enableValkey(bool $enabled = true): void
{
    config(['sakala.valkey.enabled' => $enabled]);
}

/**
 * An online runtime node with the `managed-valkey` capability that reported
 * its role, in `$region` (none by default).
 */
function valkeyNode(?string $region = null, int $capacityMb = VALKEY_NODE_CAPACITY_MB, bool $available = true, array $capabilities = VALKEY_NODE_CAPABILITIES, bool $reported = true): AgentNode
{
    return databaseNode($region, overrides: [
        'capabilities' => $capabilities,
        'metadata' => [
            'runtime' => ['region' => $region],
            'workloads' => [],
            ...($reported ? ['valkey' => ['capacity_mb' => $capacityMb, 'available' => $available]] : []),
        ],
    ]);
}

/** @return array<string, string> */
function valkeyNodeHeaders(AgentNode $node): array
{
    return databaseNodeHeaders($node);
}

/**
 * A team with a user in every role, plus an outsider and an admin, under a
 * profile with room for two 64 MB stores.
 *
 * @return array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}
 */
function valkeyTeam(array $profile = []): array
{
    return databaseTeam(['max_valkeys' => 2, 'max_valkey_memory_mb' => 128, ...$profile]);
}

function valkeysUrl(string $workspaceId, string $suffix = ''): string
{
    return "/api/v1/app/workspaces/{$workspaceId}/valkeys{$suffix}";
}

function valkeyIn(Workspace $workspace, AgentNode $node, array $attributes = []): ManagedValkey
{
    return ManagedValkey::factory()->inWorkspace($workspace)->onNode($node)->create($attributes);
}

function attachValkey(ManagedValkey $valkey, Project $project): ValkeyAttachment
{
    $attachment = ValkeyAttachment::query()->create(['valkey_id' => $valkey->id, 'project_id' => $project->id]);
    $project->refreshValkeyFingerprint();
    $project->save();

    return $attachment;
}

/** The newest command of the type for the store, as the API created it. */
function valkeyCommand(ManagedValkey $valkey, AgentCommandType $type): AgentCommand
{
    return AgentCommand::query()->where('valkey_id', $valkey->id)->where('type', $type)->latest('created_at')->orderByDesc('id')->firstOrFail();
}

/** A command for the store in the given status, created the way the API does. */
function issuedValkeyCommand(ManagedValkey $valkey, AgentCommandType $type, AgentCommandStatus $status = AgentCommandStatus::Running, ?string $password = null, int $sizeMb = 128, ValkeyLockSource $source = ValkeyLockSource::Admin): AgentCommand
{
    $service = app(ValkeyCommandService::class);

    $command = match ($type) {
        AgentCommandType::CreateValkey => $service->create($valkey, $password ?? (string) $valkey->password),
        AgentCommandType::DeleteValkey => $service->delete($valkey),
        AgentCommandType::RotateValkeyPassword => $service->rotatePassword($valkey, $password ?? 'RotatedPassword0123456789abcdefXY'),
        AgentCommandType::ResizeValkey => $service->resize($valkey, $sizeMb),
        AgentCommandType::LockValkey => $service->lock($valkey, $source),
        AgentCommandType::UnlockValkey => $service->unlock($valkey),
        default => throw new InvalidArgumentException('Not a Valkey command.'),
    };

    $command->update(['status' => $status, 'claimed_at' => $status === AgentCommandStatus::Pending ? null : now()]);

    return $command->refresh();
}

/**
 * What the agent reports on `complete` for the store, per the contract
 * (docs/AGENT_API.md "Command Valkey").
 *
 * @return array<string, mixed>
 */
function valkeyResultFor(ManagedValkey $valkey, AgentCommandType $type, string $host = ManagedValkeyFactory::HOST): array
{
    return match ($type) {
        AgentCommandType::CreateValkey => [
            'valkey_id' => $valkey->id,
            'mode' => $valkey->mode->value,
            'maxmemory_mb' => $valkey->maxmemory_mb,
            'memory_limit_mb' => 2 * $valkey->maxmemory_mb + ManagedValkey::NODE_OVERHEAD_MB,
            'network' => ManagedValkey::networkNameFor($valkey->id),
            'endpoint' => ['host' => $host, 'port' => ManagedValkey::PORT],
            'created' => true,
            'locked' => false,
        ],
        AgentCommandType::DeleteValkey => ['valkey_id' => $valkey->id, 'deleted' => true],
        AgentCommandType::RotateValkeyPassword => ['valkey_id' => $valkey->id, 'disconnected_clients' => 2],
        AgentCommandType::LockValkey => ['valkey_id' => $valkey->id, 'locked' => true, 'disconnected_clients' => 1],
        AgentCommandType::UnlockValkey => ['valkey_id' => $valkey->id, 'locked' => false, 'disconnected_clients' => 0],
        AgentCommandType::ResizeValkey => ['valkey_id' => $valkey->id, 'maxmemory_mb' => 128, 'memory_limit_mb' => 320, 'restarted' => false],
        default => throw new InvalidArgumentException('Not a Valkey command.'),
    };
}

/** @param  array<string, mixed>|null  $result */
function completeValkeyCommand(AgentNode $node, AgentCommand $command, ?array $result = null): void
{
    test()->withHeaders(valkeyNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => $result])
        ->assertNoContent();
}

function failValkeyCommand(AgentNode $node, AgentCommand $command, string $code = 'valkey_operation_failed', string $message = 'docker refused'): void
{
    test()->withHeaders(valkeyNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => $code, 'error_message' => $message])
        ->assertNoContent();
}

/**
 * A full heartbeat of a Valkey node: the base payload with the Valkey fields
 * of the protocol fixture, optionally replaced.
 *
 * @param  array<string, mixed>|null  $role
 * @param  list<array<string, mixed>>|null  $valkeys
 */
function valkeyHeartbeat(?array $role = null, ?array $valkeys = null): array
{
    $fixture = agentHeartbeatFixture('valkey-host');
    $payload = heartbeatPayload(['capabilities' => $fixture['capabilities']]);
    $payload['metadata']['valkey'] = $role ?? $fixture['metadata']['valkey'];
    $payload['metadata']['valkeys'] = $valkeys ?? $fixture['metadata']['valkeys'];
    $payload['metadata']['detail_counts']['valkeys'] = count($payload['metadata']['valkeys']);

    return $payload;
}
