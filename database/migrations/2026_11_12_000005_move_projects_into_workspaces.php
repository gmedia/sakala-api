<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Gives every existing user a personal workspace and moves their projects into
 * it. `projects.user_id` stays as the creator. Users are read in chunks and
 * written in batches, and projects move in one statement, so the time grows
 * with the number of users, not with the number of queries per user.
 *
 * The personal slug is the username: usernames are unique and no team exists
 * yet, so the backfill cannot collide. Users who already have a personal
 * workspace are skipped, which makes a repeated run harmless.
 *
 * `projects.workspace_id` stays nullable for the same reason as `user_id`:
 * soft-deleted projects of a deleted account wait for the purge without an
 * owner. The foreign key is RESTRICT, so a live project blocks the deletion of
 * its workspace.
 */
return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Null follows config('sakala.workspaces.max_owned_teams_per_user').
            $table->unsignedSmallInteger('max_owned_teams')->nullable();
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->nullable()->after('user_id')->constrained('workspaces')->restrictOnDelete();
            $table->index(['workspace_id', 'created_at']);
        });

        DB::table('users')
            ->select(['id', 'name', 'username'])
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('workspaces')
                ->whereColumn('workspaces.personal_user_id', 'users.id'))
            ->chunkById(self::CHUNK, function ($users): void {
                $now = now();
                $workspaces = [];
                $members = [];

                foreach ($users as $user) {
                    $workspaceId = (string) Str::uuid7();

                    $workspaces[] = [
                        'id' => $workspaceId,
                        'name' => mb_substr((string) $user->name, 0, 100),
                        'slug' => (string) $user->username,
                        'type' => 'personal',
                        'personal_user_id' => $user->id,
                        'limit_profile_id' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $members[] = [
                        'id' => (string) Str::uuid7(),
                        'workspace_id' => $workspaceId,
                        'user_id' => $user->id,
                        'role' => 'owner',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('workspaces')->insert($workspaces);
                DB::table('workspace_members')->insert($members);
            });

        // One correlated UPDATE, the same on PostgreSQL and SQLite.
        DB::statement(
            'UPDATE projects SET workspace_id = (SELECT workspaces.id FROM workspaces WHERE workspaces.personal_user_id = projects.user_id) '
            .'WHERE workspace_id IS NULL AND user_id IS NOT NULL'
        );
    }

    /**
     * Refuses once a team exists: its projects would fall back to whoever
     * created them, which is not where their members expect them.
     */
    public function down(): void
    {
        if (DB::table('workspaces')->where('type', 'team')->exists()) {
            throw new RuntimeException('Team workspaces exist. Rolling back would hand their projects to their creators; delete the teams first.');
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'created_at']);
            $table->dropConstrainedForeignId('workspace_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('max_owned_teams');
        });

        DB::table('workspace_members')->delete();
        DB::table('workspaces')->delete();
    }
};
