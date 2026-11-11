<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function workspaceBackfillMigration(): object
{
    return require database_path('migrations/2026_11_12_000005_move_projects_into_workspaces.php');
}

test('the backfill gives every user a personal workspace and moves their projects into it', function (): void {
    $alice = User::factory()->create(['name' => 'Alice', 'username' => 'alice']);
    $bob = User::factory()->create(['username' => 'bob']);
    $live = Project::factory()->for($alice)->create();
    $trashed = Project::factory()->for($bob)->create();
    $trashed->delete();
    $ownerless = Project::factory()->for($bob)->create();
    $ownerless->delete();

    $migration = workspaceBackfillMigration();
    $migration->down();

    expect(Schema::hasColumn('projects', 'workspace_id'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'max_owned_teams'))->toBeFalse()
        ->and(DB::table('workspaces')->count())->toBe(0);

    DB::table('projects')->where('id', $ownerless->id)->update(['user_id' => null]);

    // More users than one chunk, inserted the way old rows exist: without a workspace.
    $now = now();
    DB::table('users')->insert(collect(range(1, 1201))->map(fn (int $i): array => [
        'name' => "Pengguna {$i}",
        'username' => "pengguna-{$i}",
        'email' => "pengguna-{$i}@example.test",
        'password' => 'x',
        'role' => 'user',
        'created_at' => $now,
        'updated_at' => $now,
    ])->all());

    $migration->up();

    $userCount = DB::table('users')->count();

    expect(DB::table('workspaces')->count())->toBe($userCount)
        ->and(DB::table('workspaces')->where('type', 'personal')->distinct()->count('personal_user_id'))->toBe($userCount)
        ->and(DB::table('workspace_members')->where('role', 'owner')->count())->toBe($userCount);

    $alicePersonal = DB::table('workspaces')->where('personal_user_id', $alice->id)->first();
    expect($alicePersonal->slug)->toBe('alice')
        ->and($alicePersonal->name)->toBe('Alice')
        ->and($alicePersonal->limit_profile_id)->toBeNull()
        ->and(DB::table('workspace_members')->where('workspace_id', $alicePersonal->id)->where('user_id', $alice->id)->value('role'))->toBe('owner')
        ->and(DB::table('projects')->where('id', $live->id)->value('workspace_id'))->toBe($alicePersonal->id)
        ->and(DB::table('projects')->where('id', $live->id)->value('user_id'))->toBe($alice->id)
        ->and(DB::table('projects')->where('id', $trashed->id)->value('workspace_id'))
        ->toBe(DB::table('workspaces')->where('personal_user_id', $bob->id)->value('id'))
        ->and(DB::table('projects')->where('id', $ownerless->id)->value('workspace_id'))->toBeNull()
        ->and(DB::table('workspaces')->where('personal_user_id', DB::table('users')->where('username', 'pengguna-1201')->value('id'))->value('slug'))
        ->toBe('pengguna-1201');
});

test('rolling back refuses once a team exists', function (): void {
    teamWorkspace(User::factory()->create());

    expect(fn () => workspaceBackfillMigration()->down())
        ->toThrow(RuntimeException::class, 'Team workspaces exist');

    expect(Schema::hasColumn('projects', 'workspace_id'))->toBeTrue();
});
