<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseExtension;
use App\Enums\DatabaseStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function extensionsUrl(ManagedDatabase $database): string
{
    return "/api/v1/app/workspaces/{$database->workspace_id}/databases/{$database->id}/extensions";
}

test('installing an extension asks the database node with the database name and the extension', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')
        ->postJson(extensionsUrl($database), ['extension' => 'vector'])
        ->assertAccepted()
        ->assertJsonPath('data.id', $database->id)
        ->assertJsonPath('data.last_failure', null);

    // The same install while the first is on its way changes nothing.
    $this->postJson(extensionsUrl($database), ['extension' => 'vector'])->assertAccepted();
    $this->postJson(extensionsUrl($database), ['extension' => 'pg_trgm'])->assertAccepted();

    $installs = AgentCommand::query()->where('type', AgentCommandType::InstallDatabaseExtension)->orderBy('created_at')->orderBy('id')->get();

    expect($installs)->toHaveCount(2)
        ->and($installs->pluck('payload')->sortBy('extension')->values()->all())->toBe([
            ['database' => $database->pg_database, 'extension' => 'pg_trgm'],
            ['database' => $database->pg_database, 'extension' => 'vector'],
        ])
        ->and($installs->every(fn (AgentCommand $install): bool => $install->agent_node_id === $node->id && $install->project_id === null))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'database.extension_install_requested')->count())->toBe(2);

    $this->withHeaders(databaseNodeHeaders($node))
        ->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'InstallDatabaseExtension');
});

test('only allow-listed extensions are accepted', function (mixed $extension): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->postJson(extensionsUrl($database), ['extension' => $extension])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('extension');
})->with(['postgis', 'plpython3u', 'pgvector', '', null]);

test('every allow-listed extension is accepted', function (string $extension): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['owner'], 'web')->postJson(extensionsUrl($database), ['extension' => $extension])->assertAccepted();
})->with(['pgcrypto', 'citext', 'pg_trgm', 'unaccent', 'hstore', 'vector']);

test('the authorization matrix for installing extensions', function (string $role, int $status): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team[$role], 'web')->postJson(extensionsUrl($database), ['extension' => 'citext'])->assertStatus($status);
})->with([
    'owner' => ['owner', 202],
    'member' => ['member', 202],
    'viewer' => ['viewer', 403],
    'outsider' => ['outsider', 403],
    'admin' => ['admin', 202],
]);

test('a locked database still gets extensions, an unready one does not', function (DatabaseStatus $status, int $expected): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => $status]);

    $this->actingAs($team['owner'], 'web')->postJson(extensionsUrl($database), ['extension' => 'citext'])->assertStatus($expected);
})->with([
    'locked' => [DatabaseStatus::Locked, 202],
    'provisioning' => [DatabaseStatus::Provisioning, 409],
    'failed' => [DatabaseStatus::Failed, 409],
]);

test('an extension the node cannot install shows as the last failure, classified for the operator', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $install = app(DatabaseCommandService::class)->installExtension($database, DatabaseExtension::Vector);
    $install->update(['status' => AgentCommandStatus::Running]);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$install->id}/fail", ['error_code' => 'database_extension_unavailable', 'error_message' => 'vector.control missing'])
        ->assertNoContent();

    $this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")
        ->assertOk()
        ->assertJsonPath('data.last_failure.command', 'InstallDatabaseExtension')
        ->assertJsonPath('data.last_failure.code', 'database_extension_unavailable')
        ->assertJsonPath('data.last_failure.user_fixable', false)
        ->assertJsonPath('data.last_failure.summary', 'Extension ini belum tersedia di server database. Operator Sakala perlu memasangnya di server dulu.');

    expect(AuditEvent::query()->where('action', 'database.extension_install_failed')->sole()->metadata)
        ->toMatchArray(['extension' => 'vector', 'error_code' => 'database_extension_unavailable']);

    // A new request clears it.
    $this->actingAs($team['owner'], 'web')->postJson(extensionsUrl($database), ['extension' => 'citext'])->assertAccepted()->assertJsonPath('data.last_failure', null);
});

test('a completed install is audited', function (): void {
    $node = databaseNode();
    $database = databaseIn(databaseTeam()['workspace'], $node);
    $install = app(DatabaseCommandService::class)->installExtension($database, DatabaseExtension::Hstore);
    $install->update(['status' => AgentCommandStatus::Running]);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$install->id}/complete", ['result' => ['database' => $database->pg_database, 'extension' => 'hstore', 'created' => true]])
        ->assertNoContent();

    expect(AuditEvent::query()->where('action', 'database.extension_installed')->sole()->metadata)->toMatchArray(['extension' => 'hstore'])
        ->and(databaseStatus($database))->toBe(DatabaseStatus::Ready);
});

test('a failed creation shows why, and only a refused extension is the user\'s to fix', function (string $code, bool $userFixable): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $create = issuedDatabaseCommand($database, AgentCommandType::CreateDatabase);

    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$create->id}/fail", ['error_code' => $code, 'error_message' => 'x'])
        ->assertNoContent();

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases")
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.last_failure.code', $code)
        ->assertJsonPath('data.0.last_failure.user_fixable', $userFixable);
})->with([
    ['database_extension_not_allowed', true],
    ['database_conflict', false],
    ['database_admin_unavailable', false],
    ['database_operation_failed', false],
    ['database_name_invalid', false],
    ['unsupported_runtime_command', false],
]);
