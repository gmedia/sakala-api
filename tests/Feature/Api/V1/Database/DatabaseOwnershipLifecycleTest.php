<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The account deletion request as the console sends it: its origin gives the request a session. */
function deleteOwnAccount(object $test, User $user): mixed
{
    return $test->withHeader('Origin', 'http://app.sakala.localhost:5173')
        ->actingAs($user, 'web')
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email, 'current_password' => 'password']);
}

test('deleting a project detaches its database, which survives', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    attachDatabase($database, $project);

    $this->actingAs($team['member'], 'web')->deleteJson("/api/v1/app/projects/{$project->id}")->assertNoContent();

    $audit = AuditEvent::query()->where('action', 'database.detached')->sole();

    expect(DatabaseAttachment::query()->count())->toBe(0)
        ->and(databaseStatus($database))->toBe(DatabaseStatus::Ready)
        ->and($audit->actor_id)->toBe((string) $team['member']->id)
        ->and($audit->metadata)->toMatchArray(['project_id' => $project->id, 'reason' => 'project_deleted']);

    // Now it can be deleted.
    $this->deleteJson("/api/v1/app/workspaces/{$team['workspace']->id}/databases/{$database->id}")->assertAccepted();
});

test('a team with a database cannot be deleted until the database is on its way out', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    $database = databaseIn($team['workspace'], $node);
    $url = "/api/v1/app/workspaces/{$team['workspace']->id}";

    $this->actingAs($team['owner'], 'web')
        ->deleteJson($url)
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Workspace ini masih punya database. Hapus database-nya dulu, lalu hapus workspace-nya.',
            'code' => 'WORKSPACE_NOT_EMPTY',
            'projects' => 0,
            'databases' => 1,
            'buckets' => 0,
            'valkeys' => 0,
        ]);

    $this->deleteJson("{$url}/databases/{$database->id}")->assertAccepted();
    $this->deleteJson($url)->assertNoContent();

    // The drop finishes without a workspace.
    $remaining = ManagedDatabase::query()->findOrFail($database->id);

    expect($remaining->workspace_id)->toBeNull()
        ->and($remaining->status)->toBe(DatabaseStatus::Deleting)
        ->and(Workspace::query()->whereKey($team['workspace']->id)->exists())->toBeFalse();
});

test('a team with both projects and databases names both', function (): void {
    $node = databaseNode();
    $team = databaseTeam();
    databaseIn($team['workspace'], $node);
    Project::factory()->inWorkspace($team['workspace'])->create();

    $this->actingAs($team['owner'], 'web')
        ->deleteJson("/api/v1/app/workspaces/{$team['workspace']->id}")
        ->assertConflict()
        ->assertJsonPath('message', 'Workspace ini masih punya proyek dan database. Hapus keduanya dulu, lalu hapus workspace-nya.')
        ->assertJsonPath('projects', 1)
        ->assertJsonPath('databases', 1);
});

test('deleting an account drops the databases of its personal workspace', function (): void {
    $node = databaseNode();
    $user = User::factory()->create();
    $workspace = $user->personalWorkspace()->firstOrFail();
    $database = databaseIn($workspace, $node);
    $project = Project::factory()->for($user)->create();
    attachDatabase($database, $project);

    deleteOwnAccount($this, $user)->assertNoContent();

    $drop = AgentCommand::query()->where('database_id', $database->id)->where('type', AgentCommandType::DropDatabase)->sole();
    $remaining = ManagedDatabase::query()->findOrFail($database->id);

    expect($remaining->status)->toBe(DatabaseStatus::Deleting)
        ->and($remaining->workspace_id)->toBeNull()
        ->and($drop->agent_node_id)->toBe($node->id)
        ->and(AuditEvent::query()->where('action', 'database.delete_requested')->sole()->metadata['reason'])->toBe('account_deleted')
        ->and(User::query()->whereKey($user->id)->exists())->toBeFalse();

    // The node can still finish the drop.
    $drop->update(['status' => AgentCommandStatus::Running]);
    $this->withHeaders(databaseNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$drop->id}/complete", ['result' => null])
        ->assertNoContent();

    expect(ManagedDatabase::withTrashed()->findOrFail($database->id)->trashed())->toBeTrue();
});

test('the sole owner of a team that still has a database cannot delete the account', function (): void {
    $node = databaseNode();
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, [[User::factory()->create(), WorkspaceRole::Member]]);
    databaseIn($team, $node);

    deleteOwnAccount($this, $owner)
        ->assertConflict()
        ->assertJsonPath('code', 'LAST_WORKSPACE_OWNER')
        ->assertJsonPath('workspaces.0.id', $team->id);

    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});
