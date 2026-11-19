<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\ManagedDatabase;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

function credentialsUrl(ManagedDatabase $database): string
{
    return "/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/credentials";
}

function rotateUrl(ManagedDatabase $database): string
{
    return "/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/rotate-password";
}

test('the owner reads the connection details, uncached, and the reveal is audited without the password', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $password = (string) $database->password;

    $response = $this->actingAs($team['owner'], 'web')
        ->getJson(credentialsUrl($database))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => [
            'host' => DATABASE_NODE_HOST,
            'port' => DATABASE_NODE_PORT,
            'database' => $database->pg_database,
            'username' => $database->pg_role,
            'password' => $password,
            'url' => "postgresql://{$database->pg_role}:{$password}@".DATABASE_NODE_HOST.':'.DATABASE_NODE_PORT."/{$database->pg_database}?sslmode=require",
            'sslmode' => 'require',
        ]]);

    $audit = AuditEvent::query()->where('action', 'database.credentials_viewed')->sole();

    expect($audit->actor_id)->toBe((string) $team['owner']->id)
        ->and($audit->subject_id)->toBe($database->id)
        ->and($audit->metadata)->toMatchArray(['by_workspace_member' => true])
        ->and($audit->ip_address)->toBeNull()
        ->and(json_encode($audit->metadata))->not->toContain($password);

    expect($response->json('data.password'))->toBe($password);
});

test('only owners and admins read credentials', function (string $role, int $status): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team[$role], 'web')->getJson(credentialsUrl($database))->assertStatus($status);

    expect(AuditEvent::query()->where('action', 'database.credentials_viewed')->count())->toBe($status === 200 ? 1 : 0);
})->with([
    'owner' => ['owner', 200],
    'member' => ['member', 403],
    'viewer' => ['viewer', 403],
    'outsider' => ['outsider', 403],
    'admin' => ['admin', 200],
]);

test('an admin outside the workspace leaves the address and user agent in the audit', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['admin'], 'web')
        ->withHeaders(['User-Agent' => 'admin-browser'])
        ->getJson(credentialsUrl($database))
        ->assertOk();

    $audit = AuditEvent::query()->where('action', 'database.credentials_viewed')->sole();

    expect($audit->metadata)->toMatchArray(['by_workspace_member' => false])
        ->and($audit->ip_address)->not->toBeNull()
        ->and($audit->user_agent)->toBe('admin-browser');
});

test('a database node that has not reported its endpoint leaves host, port, and url empty', function (): void {
    $node = databaseNode(endpoint: false);
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->getJson(credentialsUrl($database))
        ->assertOk()
        ->assertJsonPath('data.host', null)
        ->assertJsonPath('data.port', null)
        ->assertJsonPath('data.url', null)
        ->assertJsonPath('data.username', $database->pg_role);
});

test('an IPv6 endpoint is bracketed in the url only', function (): void {
    $node = databaseNode(overrides: ['metadata' => ['database' => ['endpoint' => ['host' => 'fd00::30', 'port' => 6432]]]]);
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $response = $this->actingAs($team['owner'], 'web')->getJson(credentialsUrl($database))->assertOk();

    expect($response->json('data.host'))->toBe('fd00::30')
        ->and($response->json('data.url'))->toContain('@[fd00::30]:6432/');
});

test('credentials of a database that is not provisioned are refused', function (DatabaseStatus $status): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => $status]);

    $this->actingAs($team['owner'], 'web')
        ->getJson(credentialsUrl($database))
        ->assertConflict()
        ->assertJsonPath('code', 'DATABASE_NOT_READY')
        ->assertJsonPath('status', $status->value);
})->with([DatabaseStatus::Provisioning, DatabaseStatus::Failed, DatabaseStatus::Deleting]);

test('a locked database still shows its credentials', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Locked]);

    $this->actingAs($team['owner'], 'web')->getJson(credentialsUrl($database))->assertOk();
});

test('rotating asks the node for a new password and keeps the old one until it reports', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $old = (string) $database->password;

    $this->actingAs($team['member'], 'web')->postJson(rotateUrl($database))->assertAccepted()->assertJsonPath('data.status', 'ready');

    $command = databaseCommand($database, AgentCommandType::RotateDatabasePassword);
    $new = Crypt::decryptString($command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY]);

    expect($database->fresh()->password)->toBe($old)
        ->and($new)->not->toBe($old)
        ->and(strlen($new))->toBe(ManagedDatabase::PASSWORD_LENGTH)
        ->and(array_keys($command->payload))->toBe(['role', 'password'])
        ->and($command->payload['role'])->toBe($database->pg_role)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and(AuditEvent::query()->where('action', 'database.password_rotation_requested')->count())->toBe(1);

    // A second rotation waits for the first.
    $this->postJson(rotateUrl($database))
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Penggantian password sebelumnya masih berjalan. Tunggu sampai selesai, lalu coba lagi.',
            'code' => 'DATABASE_BUSY',
        ]);

    $command->update(['status' => AgentCommandStatus::Running]);
    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($database->fresh()->password)->toBe($new)
        ->and(AuditEvent::query()->where('action', 'database.password_rotated')->count())->toBe(1);

    $this->actingAs($team['owner'], 'web')->getJson(credentialsUrl($database))->assertJsonPath('data.password', $new);
});

test('a failed rotation keeps the old password and is audited', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $old = (string) $database->password;
    $command = issuedDatabaseCommand($database, AgentCommandType::RotateDatabasePassword);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'database_unavailable', 'error_message' => 'instance down'])
        ->assertNoContent();

    expect($database->fresh()->password)->toBe($old)
        ->and(AuditEvent::query()->where('action', 'database.password_rotation_failed')->sole()->metadata)
        ->toMatchArray(['error_code' => 'database_unavailable']);
});

test('the authorization matrix for rotating', function (string $role, int $status): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team[$role], 'web')->postJson(rotateUrl($database))->assertStatus($status);

    expect(AgentCommand::query()->where('type', AgentCommandType::RotateDatabasePassword)->count())->toBe($status === 202 ? 1 : 0);
})->with([
    'owner' => ['owner', 202],
    'member' => ['member', 202],
    'viewer' => ['viewer', 403],
    'outsider' => ['outsider', 403],
    'admin' => ['admin', 202],
]);

test('a locked database can rotate its password, an unready one cannot', function (DatabaseStatus $status, int $expected): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => $status]);

    $this->actingAs($team['owner'], 'web')->postJson(rotateUrl($database))->assertStatus($expected);
})->with([
    'locked' => [DatabaseStatus::Locked, 202],
    'provisioning' => [DatabaseStatus::Provisioning, 409],
    'deleting' => [DatabaseStatus::Deleting, 409],
]);
