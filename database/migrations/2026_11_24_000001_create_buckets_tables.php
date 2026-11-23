<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Managed object storage buckets (ADR-021). A bucket belongs to a workspace,
 * lives on one node with the `managed-s3` capability, and attaches to
 * projects of the same workspace, at most one per project.
 *
 * `s3_bucket` and `access_key_id` are random values on the node, never derived
 * from what people type and never reused; `name` is what people see, unique
 * per workspace among live rows (a partial index, because deleted rows stay
 * soft deleted). `quota_mb` is what the node applies; `reserved_quota_mb` is
 * what counts toward the workspace and node allocation, the larger of the
 * two while a resize is open. `workspace_id` turns null when an account
 * deletion removes the personal workspace while its DeleteBucket is still on
 * the way, so the delete can still finish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buckets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('name', 100);
            $table->string('region', 32)->nullable();
            $table->string('status', 16);
            $table->foreignUuid('node_id')->constrained('agent_nodes')->restrictOnDelete();
            $table->string('s3_bucket', 63)->unique();
            $table->string('access_key_id', 40)->unique();
            // Encrypted cast; null once the bucket is deleted.
            $table->text('secret_access_key')->nullable();
            $table->unsignedInteger('quota_mb');
            $table->unsignedInteger('reserved_quota_mb');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestampTz('usage_checked_at')->nullable();
            $table->timestampTz('storage_warned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // Workspace list, the count, and the reserved storage sum.
            $table->index(['workspace_id', 'status']);
            // Heartbeat size reports match a node's buckets by name.
            $table->index(['node_id', 's3_bucket']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX buckets_workspace_id_name_live_unique
             ON buckets (workspace_id, name)
             WHERE deleted_at IS NULL'
        );

        Schema::create('bucket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('bucket_id')->constrained('buckets')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['bucket_id', 'project_id']);
            // One bucket per project: its variables have fixed names, so a
            // second one would have nowhere to go.
            $table->unique('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bucket_attachments');
        DB::statement('DROP INDEX IF EXISTS buckets_workspace_id_name_live_unique');
        Schema::dropIfExists('buckets');
    }
};
