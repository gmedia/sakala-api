<?php

declare(strict_types=1);

use App\Enums\DatabaseStatus;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// ─── Connection details for every member ─────────────────────────────────────

test('every member who can see a ready database sees where to connect, without the password', function (string $role): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $url = "/api/v1/app/workspaces/{$team['workspace']->id}/databases";
    $expected = [
        'host' => DATABASE_NODE_HOST,
        'port' => DATABASE_NODE_PORT,
        'database' => $database->pg_database,
        'username' => $database->pg_role,
    ];

    $this->actingAs($team[$role], 'web');

    $show = $this->getJson("{$url}/{$database->id}")
        ->assertOk()
        ->assertJsonPath('data.connection', $expected);
    $this->getJson($url)->assertOk()->assertJsonPath('data.0.connection', $expected);

    expect($show->getContent())->not->toContain((string) $database->password)
        ->and($show->json('data.connection'))->not->toHaveKeys(['password', 'url']);
})->with(['owner', 'member', 'viewer']);

test('a locked database keeps its connection details, since only the role is turned off', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Locked]);

    $this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")
        ->assertJsonPath('data.connection.database', $database->pg_database);
});

test('there is nothing to connect to before the database is ready or while it goes away', function (DatabaseStatus $status): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['status' => $status]);

    $this->actingAs($team['member'], 'web')
        ->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")
        ->assertOk()
        ->assertJsonPath('data.connection', null);
})->with([DatabaseStatus::Provisioning, DatabaseStatus::Failed, DatabaseStatus::Deleting]);

test('the connection waits for the database node to report its address', function (): void {
    $node = databaseNode(endpoint: false);
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);

    $this->actingAs($team['member'], 'web')
        ->getJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.connection', null);
});

test('the database list reads every node address without a query per database', function (): void {
    $team = databaseTeam(['max_databases' => 10]);
    $url = "/api/v1/app/workspaces/{$team['workspace']->id}/databases";
    databaseIn($team['workspace'], databaseNode());
    $this->actingAs($team['member'], 'web');

    DB::enableQueryLog();
    $this->getJson($url)->assertOk();
    $baseline = count(DB::getQueryLog());

    foreach (range(1, 3) as $ignored) {
        databaseIn($team['workspace'], databaseNode());
    }

    DB::flushQueryLog();
    $this->getJson($url)->assertOk()->assertJsonCount(4, 'data');

    expect(count(DB::getQueryLog()))->toBe($baseline);
});

// ─── Workspace default region ────────────────────────────────────────────────

test('members read the workspace default region, and null means any region', function (string $role): void {
    $team = databaseTeam(['default_region' => 'jkt']);
    $workspaceId = $team['workspace']->id;

    $this->actingAs($team[$role], 'web')
        ->getJson("/api/v1/app/workspaces/{$workspaceId}")
        ->assertOk()
        ->assertJsonPath('data.default_region', 'jkt');

    $listed = collect($this->getJson('/api/v1/app/workspaces')->assertOk()->json('data'))->firstWhere('id', $workspaceId);
    expect($listed['default_region'])->toBe('jkt');

    LimitProfile::query()->whereKey($team['workspace']->limit_profile_id)->update(['default_region' => null]);

    $this->getJson("/api/v1/app/workspaces/{$workspaceId}")->assertJsonPath('data.default_region', null);
})->with(['owner', 'member', 'viewer']);

test('a workspace without a limit profile has no default region', function (): void {
    $user = User::factory()->create();

    $personal = collect($this->actingAs($user, 'web')->getJson('/api/v1/app/workspaces')->assertOk()->json('data'))
        ->firstWhere('type', 'personal');

    expect($personal)->toHaveKey('default_region')
        ->and($personal['default_region'])->toBeNull();
});

// ─── Databases on the project list ───────────────────────────────────────────

test('the project list names the database each project uses', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node, ['name' => 'toko-db']);
    $withDatabase = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko', 'created_at' => now()->subMinute()]);
    Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Blog']);
    attachDatabase($database, $withDatabase);

    $projects = collect($this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/projects?workspace_id={$team['workspace']->id}")
        ->assertOk()
        ->json('data'))->keyBy('name');

    expect($projects['Toko']['databases'])->toBe([['id' => $database->id, 'name' => 'toko-db', 'status' => 'ready']])
        ->and($projects['Blog']['databases'])->toBe([]);
});

test('a deleted database is not listed on its former project', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    attachDatabase($database, $project);
    $database->delete();

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects?workspace_id={$team['workspace']->id}")
        ->assertJsonPath('data.0.databases', []);
});
