<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Managed Valkey stores (ADR-023). A store belongs to a workspace, runs as
 * one container on one node with the `managed-valkey` capability, and
 * attaches to projects of the same workspace that run on that node, at most
 * one per project.
 *
 * `name` is what people see, unique per workspace among live rows (a partial
 * index, because deleted rows stay soft deleted). `maxmemory_mb` is what the
 * node applies; `reserved_maxmemory_mb` is what counts toward the workspace
 * and node allocation, the larger of the two while a resize is open.
 * `network`, `host`, and `port` come from CreateValkey's result. The usage
 * columns hold the node's last reading. `workspace_id` turns null when an
 * account deletion removes the personal workspace while its DeleteValkey is
 * still on the way, so the delete can still finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valkeys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('name', 100);
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->string('region', 32)->nullable();
            $table->foreignUuid('node_id')->constrained('agent_nodes')->restrictOnDelete();
            // Encrypted cast; null once the store is deleted.
            $table->text('password')->nullable();
            $table->unsignedInteger('maxmemory_mb');
            $table->unsignedInteger('reserved_maxmemory_mb');
            $table->string('network', 64)->nullable();
            $table->string('host', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('lock_source', 16)->nullable();
            $table->unsignedBigInteger('used_memory_bytes')->nullable();
            $table->unsignedBigInteger('evicted_keys')->nullable();
            $table->unsignedBigInteger('errorstat_oom')->nullable();
            $table->unsignedInteger('connected_clients')->nullable();
            $table->string('aof_last_write_status', 8)->nullable();
            $table->string('container_status', 16)->nullable();
            $table->unsignedInteger('restart_count')->nullable();
            $table->timestampTz('usage_checked_at')->nullable();
            $table->timestampTz('memory_warned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Workspace list, the count, and the reserved memory sum.
            $table->index(['workspace_id', 'status']);
            // Node budget sums, and heartbeat readings matched per node.
            $table->index(['node_id', 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX valkeys_workspace_id_name_live_unique
             ON valkeys (workspace_id, name)
             WHERE deleted_at IS NULL'
        );

        Schema::create('valkey_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('valkey_id')->constrained('valkeys')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['valkey_id', 'project_id']);
            // One store per project: its variables have fixed names, so a
            // second one would have nowhere to go.
            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valkey_attachments');
        DB::statement('DROP INDEX IF EXISTS valkeys_workspace_id_name_live_unique');
        Schema::dropIfExists('valkeys');
    }
};
