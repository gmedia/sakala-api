<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\ManagedDatabase;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function completeDatabaseCommand(AgentNode $node, AgentCommand $command): void
{
    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();
}

function failDatabaseCommand(AgentNode $node, AgentCommand $command, string $code = 'database_unavailable', string $message = 'instance down'): void
{
    test()->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => $code, 'error_message' => $message])
        ->assertNoContent();
}

/** @return list<string> */
function databaseAuditActions(ManagedDatabase $database): array
{
    return AuditEvent::query()->where('subject_id', $database->id)->orderBy('id')->pluck('action')->all();
}

test('the database node polls its database commands with the payload, password in plaintext for it only', function (): void {
    $node = databaseNode();
    $other = databaseNode();
    $runtime = AgentNode::factory()->create([
        'last_seen_at' => now(),
        'capabilities' => ['docker-runtime', 'dockerfile-build'],
        'token_hash' => hash_hmac('sha256', 'runtime-token', (string) config('app.key')),
    ]);
    $database = databaseIn(databaseTeam()['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $command = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase, AgentCommandStatus::Pending);

    $this->withHeaders(databaseNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $command->id)
        ->assertJsonPath('data.0.type', 'CreateDatabase')
        ->assertJsonPath('data.0.project_id', null)
        ->assertJsonPath('data.0.deployment_id', null)
        ->assertJsonPath('data.0.payload', [
            'database' => $database->pg_database,
            'role' => $database->pg_role,
            'password' => $database->password,
        ]);

    // Pinned to the database's node; another database node never sees it.
    $this->withHeaders(databaseNodeHeaders($other))->getJson('/api/agent/v1/commands')->assertOk()->assertJsonCount(0, 'data');

    // A node without the capability never sees database commands.
    $command->update(['agent_node_id' => $runtime->id]);
    $this->withHeaders(['Authorization' => 'Bearer runtime-token', 'X-Agent-Id' => $runtime->agent_id])
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('a node claims a database command like any other and gets a lease', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $command = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase, AgentCommandStatus::Pending);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/claim", [])
        ->assertOk();

    expect($command->fresh()->status)->toBe(AgentCommandStatus::Claimed)
        ->and($command->fresh()->lease_expires_at)->not->toBeNull();
});

test('lock and unlock payloads carry the database name only', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node);
    $lock = issuedDatabaseCommand($database, AgentCommandType::LockDatabase, AgentCommandStatus::Pending);

    expect($lock->payload)->toBe(['database' => $database->pg_database]);

    $this->withHeaders(databaseNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertJsonPath('data.0.payload', ['database' => $database->pg_database]);
});

test('a created database becomes ready and is audited', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $command = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase);

    completeDatabaseCommand($node, $command);
    completeDatabaseCommand($node, $command);

    expect(databaseStatus($database))->toBe(DatabaseStatus::Ready)
        ->and(databaseAuditActions($database))->toBe(['database.provisioned']);
});

test('a failed or expired creation marks the database failed with the code', function (string $how): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $command = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase);

    if ($how === 'fail') {
        failDatabaseCommand($node, $command, 'database_create_failed');
    } else {
        $command->update(['lease_expires_at' => now()->subMinute()]);
        $this->artisan('agent:expire-commands')->assertSuccessful();
    }

    $audit = AuditEvent::query()->where('action', 'database.provisioning_failed')->sole();

    expect(databaseStatus($database))->toBe(DatabaseStatus::Failed)
        ->and($audit->metadata['error_code'])->toBe($how === 'fail' ? 'database_create_failed' : 'command_lease_expired')
        ->and($audit->actor_type)->toBe($how === 'fail' ? AgentNode::class : 'system');
})->with(['fail', 'expire']);

test('a dropped database is soft deleted, its password erased everywhere', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['status' => DatabaseStatus::Deleting]);
    $create = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase, AgentCommandStatus::Succeeded);
    $drop = issuedDatabaseCommand($database, AgentCommandType::DropDatabase);

    completeDatabaseCommand($node, $drop);

    $dropped = ManagedDatabase::withTrashed()->findOrFail($database->id);

    expect($dropped->status)->toBe(DatabaseStatus::Deleted)
        ->and($dropped->trashed())->toBeTrue()
        ->and($dropped->password)->toBeNull()
        ->and($create->fresh()->payload)->not->toHaveKey(AgentCommandPayloadMaterializer::PASSWORD_KEY)
        ->and(databaseAuditActions($database))->toBe(['database.deleted']);
});

test('a failed drop keeps the database deleting, and deleting again sends another drop', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Deleting]);
    $drop = issuedDatabaseCommand($database, AgentCommandType::DropDatabase);

    failDatabaseCommand($node, $drop, 'database_in_use');

    expect(databaseStatus($database))->toBe(DatabaseStatus::Deleting)
        ->and(databaseAuditActions($database))->toBe(['database.drop_failed']);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")
        ->assertAccepted();

    expect(AgentCommand::query()->where('database_id', $database->id)->where('type', AgentCommandType::DropDatabase)->count())->toBe(2);
});

test('lock and unlock move the status, and a late one does not undo a newer state', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node);

    completeDatabaseCommand($node, issuedDatabaseCommand($database, AgentCommandType::LockDatabase));
    expect(databaseStatus($database))->toBe(DatabaseStatus::Locked);

    completeDatabaseCommand($node, issuedDatabaseCommand($database, AgentCommandType::UnlockDatabase));
    expect(databaseStatus($database))->toBe(DatabaseStatus::Ready);

    $database->update(['status' => DatabaseStatus::Deleting]);
    completeDatabaseCommand($node, issuedDatabaseCommand($database, AgentCommandType::LockDatabase));

    expect(databaseStatus($database))->toBe(DatabaseStatus::Deleting)
        ->and(databaseAuditActions($database))->toBe(['database.locked', 'database.unlocked']);
});

test('a failed lock is audited and changes nothing', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node);

    failDatabaseCommand($node, issuedDatabaseCommand($database, AgentCommandType::LockDatabase));

    expect(databaseStatus($database))->toBe(DatabaseStatus::Ready)
        ->and(databaseAuditActions($database))->toBe(['database.lock_failed']);
});

test('only the database node may report on its command', function (): void {
    $node = databaseNode();
    $other = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $command = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase);

    $this->withHeaders(databaseNodeHeaders($other))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertConflict();

    expect(databaseStatus($database))->toBe(DatabaseStatus::Provisioning);
});

test('the passwords are redacted from failure messages and command reports', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node);
    $current = (string) $database->password;
    $next = 'NextPassword0123456789abcdefghijklmnopqr';
    $rotate = issuedDatabaseCommand($database, AgentCommandType::RotateDatabasePassword, password: $next);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$rotate->id}/logs", ['logs' => [[
            'stream' => 'stderr',
            'message' => "ALTER ROLE with {$next} replacing {$current}",
            'recorded_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$rotate->id}/events", ['events' => [[
            'level' => 'error',
            'type' => 'database.rotate.failed',
            'message' => "could not set {$next}",
            'metadata' => ['detail' => $current],
            'occurred_at' => now()->toIso8601String(),
        ]]])
        ->assertOk();

    failDatabaseCommand($node, $rotate, 'database_rotate_failed', "role refused {$next}");

    $reports = AgentCommandReport::query()->where('agent_command_id', $rotate->id)->get();

    expect($rotate->fresh()->error_message)->toBe('role refused [REDACTED]')
        ->and($reports->pluck('message')->all())->toBe(['ALTER ROLE with [REDACTED] replacing [REDACTED]', 'could not set [REDACTED]'])
        ->and($reports->last()->metadata)->toBe(['detail' => '[REDACTED]'])
        ->and(json_encode(AuditEvent::query()->get()->toArray()))->not->toContain($next);
});
