<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DatabaseStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Services\Agent\AgentCommandPayloadMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

function databasesUrl(string $workspaceId, string $suffix = ''): string
{
    return "/api/v1/app/workspaces/{$workspaceId}/databases{$suffix}";
}

test('creating a database picks a database node, random names, and asks the node to create it', function (): void {
    $node = databaseNode('jkt');
    $team = databaseTeam();

    $response = $this->actingAs($team['member'], 'web')
        ->postJson(databasesUrl($team['workspace']->id), ['name' => '  Toko Data  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Toko Data')
        ->assertJsonPath('data.engine', 'postgresql')
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.region', 'jkt')
        ->assertJsonPath('data.size_bytes', null)
        ->assertJsonPath('data.projects', []);

    expect($response->json('data'))->not->toHaveKeys(['password', 'pg_database', 'pg_role', 'node_id']);

    $database = ManagedDatabase::query()->findOrFail($response->json('data.id'));
    $command = databaseCommand($database, AgentCommandType::CreateDatabase);

    expect($database->node_id)->toBe($node->id)
        ->and($database->pg_database)->toMatch('/^p_[a-z0-9]{20}$/')
        ->and($database->pg_role)->toMatch('/^p_[a-z0-9]{20}$/')
        // One role owns one database, so the two names differ.
        ->and($database->pg_role)->not->toBe($database->pg_database)
        ->and(strlen((string) $database->password))->toBe(ManagedDatabase::PASSWORD_LENGTH)
        ->and($database->getRawOriginal('password'))->not->toBe($database->password)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->expires_at)->toBeNull()
        ->and($command->project_id)->toBeNull()
        ->and($command->payload['database'])->toBe($database->pg_database)
        ->and($command->payload['role'])->toBe($database->pg_role)
        // Encrypted at rest, like DeployProject's environment.
        ->and($command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY])->not->toBe($database->password)
        ->and(Crypt::decryptString($command->payload[AgentCommandPayloadMaterializer::PASSWORD_KEY]))->toBe($database->password);

    $audit = AuditEvent::query()->where('action', 'database.created')->sole();
    expect($audit->subject_id)->toBe($database->id)
        ->and($audit->actor_id)->toBe((string) $team['member']->id)
        ->and(json_encode($audit->metadata))->not->toContain((string) $database->password);
});

test('the authorization matrix for reading and changing databases', function (string $role, int $list, int $create, int $rename, int $delete): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $actor = $team[$role];
    $workspaceId = $team['workspace']->id;

    $this->actingAs($actor, 'web');
    $this->getJson(databasesUrl($workspaceId))->assertStatus($list);
    $this->getJson(databasesUrl($workspaceId, "/{$database->id}"))->assertStatus($list);
    $this->postJson(databasesUrl($workspaceId), ['name' => 'baru'])->assertStatus($create);
    $this->patchJson(databasesUrl($workspaceId, "/{$database->id}"), ['name' => "nama-{$role}"])->assertStatus($rename);
    $this->deleteJson(databasesUrl($workspaceId, "/{$database->id}"))->assertStatus($delete);
})->with([
    'owner' => ['owner', 200, 201, 200, 202],
    'member' => ['member', 200, 201, 200, 202],
    'viewer' => ['viewer', 200, 403, 403, 403],
    'outsider' => ['outsider', 403, 403, 403, 403],
    'admin' => ['admin', 200, 201, 200, 202],
]);

test('another workspace does not reveal whether a database exists', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $other = databaseTeam();
    $database = databaseIn($other['workspace'], $node);

    $this->actingAs($team['owner'], 'web')
        ->getJson(databasesUrl($team['workspace']->id, "/{$database->id}"))
        ->assertNotFound();
});

test('a guest gets 401', function (): void {
    $team = databaseTeam();

    $this->getJson(databasesUrl($team['workspace']->id))->assertUnauthorized();
});

test('the list shows the workspace databases with their attached projects, newest first', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $older = databaseIn($team['workspace'], $node, ['name' => 'lama', 'created_at' => now()->subDay()]);
    $newer = databaseIn($team['workspace'], $node, ['name' => 'baru', 'size_bytes' => 4096, 'size_checked_at' => now()]);
    databaseIn(databaseTeam()['workspace'], $node, ['name' => 'orang-lain']);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko']);
    attachDatabase($older, $project);

    $this->actingAs($team['viewer'], 'web')
        ->getJson(databasesUrl($team['workspace']->id))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.0.size_bytes', 4096)
        ->assertJsonPath('data.1.projects.0.id', $project->id)
        ->assertJsonPath('data.1.projects.0.slug', $project->slug);
});

test('names are required, bounded, and unique among the live databases of a workspace', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    databaseIn($team['workspace'], $node, ['name' => 'utama']);
    databaseIn($team['workspace'], $node, ['name' => 'lama', 'status' => DatabaseStatus::Deleted, 'deleted_at' => now()]);
    databaseIn(databaseTeam()['workspace'], $node, ['name' => 'tetangga']);
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['owner'], 'web');
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson($url, ['name' => str_repeat('a', ManagedDatabase::MAX_NAME_LENGTH + 1)])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson($url, ['name' => 'utama'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson($url, ['name' => 'lama'])->assertCreated();
    $this->postJson($url, ['name' => 'tetangga'])->assertCreated();
    $this->postJson($url, ['name' => 'x', 'region' => 'Bukan Region'])->assertUnprocessable()->assertJsonValidationErrors('region');
});

test('renaming changes the display name only, and is audited', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['name' => 'awal']);
    $pgDatabase = $database->pg_database;
    databaseIn($team['workspace'], $node, ['name' => 'terpakai']);
    $url = databasesUrl($team['workspace']->id, "/{$database->id}");

    $this->actingAs($team['owner'], 'web');
    $this->patchJson($url, ['name' => 'terpakai'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patchJson($url, ['name' => 'akhir'])->assertOk()->assertJsonPath('data.name', 'akhir');
    $this->patchJson($url, ['name' => 'akhir'])->assertOk();

    expect($database->fresh()->pg_database)->toBe($pgDatabase)
        ->and(AuditEvent::query()->where('action', 'database.renamed')->sole()->metadata)
        ->toMatchArray(['from' => 'awal', 'to' => 'akhir']);
});

test('the database count limit holds at the boundary, with limit and current', function (): void {
    databaseNode();
    $team = databaseTeam(['max_databases' => 2]);
    $node = databaseNode();
    databaseIn($team['workspace'], $node);
    // A failed creation holds nothing on the node and does not keep a slot.
    databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Failed]);
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['member'], 'web');
    $this->postJson($url, ['name' => 'kedua'])->assertCreated();
    $this->postJson($url, ['name' => 'ketiga'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Workspace ini sudah punya 2 database, dan itu batasnya. Hapus database yang tidak dipakai untuk membuat yang baru.',
            'code' => 'DATABASE_LIMIT_EXCEEDED',
            'limit' => 2,
            'current' => 2,
        ]);
});

test('a database being deleted still counts until the node drops it', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_databases' => 1]);
    databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Deleting]);

    $this->actingAs($team['owner'], 'web')
        ->postJson(databasesUrl($team['workspace']->id), ['name' => 'baru'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DATABASE_LIMIT_EXCEEDED');
});

test('a profile with no databases allowed refuses with its own message, and admins are not limited', function (): void {
    databaseNode();
    $team = databaseTeam(['max_databases' => 0]);
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['owner'], 'web')
        ->postJson($url, ['name' => 'baru'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DATABASE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 0)
        ->assertJsonPath('message', 'Workspace ini belum bisa membuat database. Minta admin mengaktifkannya lewat profil batas workspace.');

    $this->actingAs($team['admin'], 'web')->postJson($url, ['name' => 'oleh-admin'])->assertCreated();
});

test('a profile without its own values follows the global defaults, and a workspace without a profile too', function (): void {
    databaseNode();
    config(['sakala.databases.default_max_count' => 1]);
    $team = databaseTeam(['max_databases' => null]);
    $personal = User::factory()->create();

    $this->actingAs($team['owner'], 'web');
    $this->postJson(databasesUrl($team['workspace']->id), ['name' => 'satu'])->assertCreated();
    $this->postJson(databasesUrl($team['workspace']->id), ['name' => 'dua'])->assertUnprocessable()->assertJsonPath('limit', 1);

    $workspaceId = $personal->personalWorkspace()->value('id');
    $this->actingAs($personal, 'web');
    $this->postJson(databasesUrl($workspaceId), ['name' => 'satu'])->assertCreated();
    $this->postJson(databasesUrl($workspaceId), ['name' => 'dua'])->assertUnprocessable()->assertJsonPath('limit', 1);
});

test('a workspace whose databases fill its storage cannot create another', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => 10]);
    databaseIn($team['workspace'], $node, ['size_bytes' => 10 * 1024 * 1024, 'status' => DatabaseStatus::Locked]);

    $this->actingAs($team['owner'], 'web')
        ->postJson(databasesUrl($team['workspace']->id), ['name' => 'baru'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Penyimpanan database workspace ini sudah penuh (10,0 MB dari 10 MB), jadi database baru belum bisa dibuat. Hapus database yang tidak dipakai, atau minta admin menaikkan kuota.',
            'code' => 'DATABASE_STORAGE_LIMIT_EXCEEDED',
            'limit_mb' => 10,
            'current_bytes' => 10 * 1024 * 1024,
        ]);
});

test('just under the storage quota a database can still be created', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => 10]);
    databaseIn($team['workspace'], $node, ['size_bytes' => 10 * 1024 * 1024 - 1]);

    $this->actingAs($team['owner'], 'web')
        ->postJson(databasesUrl($team['workspace']->id), ['name' => 'baru'])
        ->assertCreated();
});

test('a region without a database node is refused, and an empty region uses the workspace default', function (): void {
    databaseNode('jkt');
    $team = databaseTeam(['default_region' => 'sby']);
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['owner'], 'web');

    $this->postJson($url, ['name' => 'a', 'region' => 'sgp'])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Belum ada server database di region sgp. Pilih region lain, atau kosongkan agar database dibuat di region mana pun.',
            'code' => 'DATABASE_REGION_UNAVAILABLE',
            'region' => 'sgp',
        ]);

    $this->postJson($url, ['name' => 'b'])->assertConflict()->assertJsonPath('region', 'sby');
    $this->postJson($url, ['name' => 'c', 'region' => 'jkt'])->assertCreated()->assertJsonPath('data.region', 'jkt');

    expect(ManagedDatabase::query()->count())->toBe(1);
});

test('no node with the managed-postgres capability means no database anywhere', function (): void {
    databaseNode(overrides: ['capabilities' => ['docker-runtime', 'dockerfile-build']]);
    $team = databaseTeam();

    $this->actingAs($team['owner'], 'web')
        ->postJson(databasesUrl($team['workspace']->id), ['name' => 'a'])
        ->assertConflict()
        ->assertJsonPath('code', 'DATABASE_REGION_UNAVAILABLE')
        ->assertJsonPath('region', null);
});

test('a database node that is only offline is waited for, and a revoked one never is', function (): void {
    $offline = databaseNode('jkt', ['status' => AgentNodeStatus::Offline, 'last_seen_at' => now()->subHour()]);
    databaseNode('sby', ['auth_status' => AgentAuthStatus::Revoked]);
    $team = databaseTeam();
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['owner'], 'web');
    $id = $this->postJson($url, ['name' => 'a', 'region' => 'jkt'])->assertCreated()->json('data.id');
    $this->postJson($url, ['name' => 'b', 'region' => 'sby'])->assertConflict()->assertJsonPath('code', 'DATABASE_REGION_UNAVAILABLE');

    expect(ManagedDatabase::query()->findOrFail($id)->node_id)->toBe($offline->id);
});

test('placement prefers the online database node with the fewest databases', function (): void {
    $busy = databaseNode('jkt');
    $quiet = databaseNode('jkt');
    databaseIn(databaseTeam()['workspace'], $busy);
    databaseIn(databaseTeam()['workspace'], $busy);
    databaseIn(databaseTeam()['workspace'], $quiet);
    $team = databaseTeam();

    $id = $this->actingAs($team['owner'], 'web')
        ->postJson(databasesUrl($team['workspace']->id), ['name' => 'a'])
        ->assertCreated()
        ->json('data.id');

    expect(ManagedDatabase::query()->findOrFail($id)->node_id)->toBe($quiet->id);
});

test('a database node whose instance is unavailable is chosen last', function (): void {
    $down = databaseNode('jkt', ['metadata' => ['runtime' => ['region' => 'jkt'], 'database' => ['endpoint' => ['host' => '10.0.0.1', 'port' => 5432], 'available' => false]]]);
    $up = databaseNode('jkt');
    databaseIn(databaseTeam()['workspace'], $up);
    $team = databaseTeam();
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['owner'], 'web');
    $first = $this->postJson($url, ['name' => 'a'])->assertCreated()->json('data.id');

    expect(ManagedDatabase::query()->findOrFail($first)->node_id)->toBe($up->id);

    // When it is the only node, it is still chosen rather than refusing.
    $up->update(['auth_status' => AgentAuthStatus::Revoked]);
    $second = $this->postJson($url, ['name' => 'b'])->assertCreated()->json('data.id');

    expect(ManagedDatabase::query()->findOrFail($second)->node_id)->toBe($down->id);
});

test('deleting asks the node to drop the database and audits it', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $url = databasesUrl($team['workspace']->id, "/{$database->id}");

    $this->actingAs($team['member'], 'web')->deleteJson($url)->assertAccepted()->assertJsonPath('data.status', 'deleting');
    // Asking again while the drop is on its way changes nothing.
    $this->deleteJson($url)->assertAccepted();

    $drops = AgentCommand::query()->where('database_id', $database->id)->where('type', AgentCommandType::DropDatabase)->get();

    expect($drops)->toHaveCount(1)
        ->and($drops->first()->payload)->toBe(['database' => $database->pg_database, 'role' => $database->pg_role])
        ->and(AuditEvent::query()->where('action', 'database.delete_requested')->count())->toBe(1);

    // Still visible while deleting.
    $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'deleting');
});

test('a database attached to a project cannot be deleted', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko']);
    attachDatabase($database, $project);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson(databasesUrl($team['workspace']->id, "/{$database->id}"))
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Database ini masih dipasang ke proyek. Lepas dulu dari semua proyek, lalu hapus.',
            'code' => 'DATABASE_IN_USE',
            'projects' => [['id' => $project->id, 'name' => 'Toko', 'slug' => $project->slug]],
        ]);

    expect(databaseStatus($database))->toBe(DatabaseStatus::Ready);
});

test('deleting a database no node started creating cancels the creation and removes it at once', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $this->actingAs($team['owner'], 'web');
    $id = $this->postJson(databasesUrl($team['workspace']->id), ['name' => 'batal'])->json('data.id');
    $database = ManagedDatabase::query()->findOrFail($id);

    $this->deleteJson(databasesUrl($team['workspace']->id, "/{$id}"))->assertAccepted()->assertJsonPath('data.status', 'deleted');

    $create = databaseCommand($database, AgentCommandType::CreateDatabase);

    expect($create->status)->toBe(AgentCommandStatus::Cancelled)
        ->and($create->payload)->not->toHaveKey('password')
        ->and(ManagedDatabase::withTrashed()->findOrFail($id)->trashed())->toBeTrue()
        ->and(ManagedDatabase::withTrashed()->findOrFail($id)->password)->toBeNull()
        ->and(AgentCommand::query()->where('type', AgentCommandType::DropDatabase)->exists())->toBeFalse();

    $this->getJson(databasesUrl($team['workspace']->id, "/{$id}"))->assertNotFound();
    // The name is free again.
    $this->postJson(databasesUrl($team['workspace']->id), ['name' => 'batal'])->assertCreated();
    expect($node->id)->not->toBeNull();
});

test('deleting a database a node is creating waits for the node', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    issuedDatabaseCommand($database, AgentCommandType::CreateDatabase, AgentCommandStatus::Running);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson(databasesUrl($team['workspace']->id, "/{$database->id}"))
        ->assertConflict()
        ->assertJsonPath('code', 'DATABASE_BUSY');

    expect(databaseStatus($database))->toBe(DatabaseStatus::Provisioning);
});

test('a failed database is dropped on its node too, since creation may have gone halfway', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Failed]);

    $this->actingAs($team['owner'], 'web')
        ->deleteJson(databasesUrl($team['workspace']->id, "/{$database->id}"))
        ->assertAccepted()
        ->assertJsonPath('data.status', 'deleting');

    expect(databaseCommand($database, AgentCommandType::DropDatabase)->agent_node_id)->toBe($node->id);
});

test('a workspace profile limits apply per workspace, not per person', function (): void {
    databaseNode();
    $team = databaseTeam(['max_databases' => 1]);
    $url = databasesUrl($team['workspace']->id);

    $this->actingAs($team['owner'], 'web')->postJson($url, ['name' => 'satu'])->assertCreated();
    $this->actingAs($team['member'], 'web')->postJson($url, ['name' => 'dua'])->assertUnprocessable();

    expect(LimitProfile::query()->count())->toBeGreaterThan(0);
});
