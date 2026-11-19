<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DatabaseStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\DatabaseAttachment;
use App\Models\LimitProfile;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Database\DatabaseCommandService;
use Illuminate\Support\Str;

const DATABASE_NODE_HOST = '10.0.47.30';

const DATABASE_NODE_PORT = 5432;

/**
 * An online node with the `managed-postgres` capability that reported its
 * endpoint, in `$region` (none by default).
 */
function databaseNode(?string $region = null, array $overrides = [], bool $endpoint = true): AgentNode
{
    $agentId = 'agent-'.Str::uuid7();

    return AgentNode::factory()->create([
        'agent_id' => $agentId,
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'desired_state' => AgentNodeDesiredState::Active,
        'protocol_version' => 4,
        'last_seen_at' => now(),
        'capabilities' => [AgentCommandType::MANAGED_POSTGRES_CAPABILITY],
        'metadata' => [
            'runtime' => ['region' => $region],
            ...($endpoint ? ['database' => ['endpoint' => ['host' => DATABASE_NODE_HOST, 'port' => DATABASE_NODE_PORT]]] : []),
        ],
        'token_hash' => hash_hmac('sha256', databaseNodeToken($agentId), (string) config('app.key')),
        ...$overrides,
    ]);
}

/** Each database node of a test has its own token, derived from its agent ID. */
function databaseNodeToken(string $agentId): string
{
    return "token-{$agentId}";
}

/** @return array<string, string> */
function databaseNodeHeaders(AgentNode $node): array
{
    return ['Authorization' => 'Bearer '.databaseNodeToken($node->agent_id), 'X-Agent-Id' => $node->agent_id];
}

/**
 * A team with a user in every role, plus an outsider and an admin, under a
 * profile with room for databases.
 *
 * @return array{workspace: Workspace, owner: User, member: User, viewer: User, outsider: User, admin: User}
 */
function databaseTeam(array $profile = []): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $viewer = User::factory()->create();

    $workspace = teamWorkspace($owner, [[$member, WorkspaceRole::Member], [$viewer, WorkspaceRole::Viewer]], [
        'limit_profile_id' => LimitProfile::factory()->create([
            'max_active_containers' => 5,
            'max_active_deployments' => 5,
            'max_databases' => 3,
            'max_database_storage_mb' => 100,
            ...$profile,
        ])->id,
    ]);

    return [
        'workspace' => $workspace,
        'owner' => $owner,
        'member' => $member,
        'viewer' => $viewer,
        'outsider' => User::factory()->create(),
        'admin' => User::factory()->create(['role' => UserRole::Admin]),
    ];
}

function databaseIn(Workspace $workspace, AgentNode $node, array $attributes = []): ManagedDatabase
{
    return ManagedDatabase::factory()->inWorkspace($workspace)->onNode($node)->create($attributes);
}

function attachDatabase(ManagedDatabase $database, Project $project): DatabaseAttachment
{
    $attachment = DatabaseAttachment::query()->create(['database_id' => $database->id, 'project_id' => $project->id]);
    $project->refreshDatabaseFingerprint();
    $project->save();

    return $attachment;
}

/** The open command of the type for the database, as the API created it. */
function databaseCommand(ManagedDatabase $database, AgentCommandType $type): AgentCommand
{
    return AgentCommand::query()->where('database_id', $database->id)->where('type', $type)->latest('created_at')->firstOrFail();
}

/** A command for the database in the given status, created the way the API does. */
function issuedDatabaseCommand(ManagedDatabase $database, AgentCommandType $type, AgentCommandStatus $status = AgentCommandStatus::Running, ?string $password = null): AgentCommand
{
    $service = app(DatabaseCommandService::class);

    $command = match ($type) {
        AgentCommandType::CreateDatabase => $service->create($database, $password ?? (string) $database->password),
        AgentCommandType::DropDatabase => $service->drop($database),
        AgentCommandType::RotateDatabasePassword => $service->rotatePassword($database, $password ?? 'rotated-password-0123456789abcdefghijklmn'),
        AgentCommandType::LockDatabase => $service->lock($database),
        AgentCommandType::UnlockDatabase => $service->unlock($database),
        default => throw new InvalidArgumentException('Not a database command.'),
    };

    $command->update(['status' => $status, 'claimed_at' => $status === AgentCommandStatus::Pending ? null : now()]);

    return $command->refresh();
}

function databaseStatus(ManagedDatabase $database): DatabaseStatus
{
    return ManagedDatabase::withTrashed()->findOrFail($database->id)->status;
}
