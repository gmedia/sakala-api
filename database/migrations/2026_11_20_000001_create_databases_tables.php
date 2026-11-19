<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Managed PostgreSQL databases (ADR-020, #125). A database belongs to a
 * workspace, lives in the shared instance of one database node, and attaches
 * to projects of the same workspace.
 *
 * `pg_database` and `pg_role` are the random names on the node; `name` is what
 * people see, unique per workspace among live rows (a partial index, because
 * dropped rows stay soft deleted). `workspace_id` turns null when an account
 * deletion removes the personal workspace while its DropDatabase is still on
 * the way, so the drop can still finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('name', 100);
            $table->string('engine', 16);
            $table->string('region', 32)->nullable();
            $table->string('status', 16);
            $table->foreignUuid('node_id')->constrained('agent_nodes')->restrictOnDelete();
            $table->string('pg_database', 63)->unique();
            $table->string('pg_role', 63)->unique();
            // Encrypted cast; null once the database is dropped.
            $table->text('password')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestampTz('size_checked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Workspace list, quota count, and the storage sum.
            $table->index(['workspace_id', 'status']);
            // Heartbeat size reports match a node's databases by name.
            $table->index(['node_id', 'pg_database']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX databases_workspace_id_name_live_unique
             ON databases (workspace_id, name)
             WHERE deleted_at IS NULL'
        );

        Schema::create('database_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('database_id')->constrained('databases')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['database_id', 'project_id']);
            // One database per project in this phase: its variables have fixed
            // names, so a second one would have nowhere to go.
            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_attachments');
        DB::statement('DROP INDEX IF EXISTS databases_workspace_id_name_live_unique');
        Schema::dropIfExists('databases');
    }
};
