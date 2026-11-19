<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A first deployment asked for while the attached database is still being
 * created (`deploy_when_ready`), kept on the attachment so it goes away with
 * it. Pending while `deploy_requested_at` is set and `deploy_resolved_at` is
 * not; resolved with either `deploy_deployment_id` or `deploy_skipped_reason`.
 * Pending requests of a database are found through the existing unique
 * `(database_id, project_id)` index, so no new index is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_attachments', function (Blueprint $table): void {
            $table->foreignId('deploy_requested_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestampTz('deploy_requested_at')->nullable()->after('deploy_requested_by');
            $table->foreignUuid('deploy_deployment_id')->nullable()->after('deploy_requested_at')->constrained('deployments')->nullOnDelete();
            $table->string('deploy_skipped_reason', 64)->nullable()->after('deploy_deployment_id');
            $table->timestampTz('deploy_resolved_at')->nullable()->after('deploy_skipped_reason');
        });
    }

    public function down(): void
    {
        Schema::table('database_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deploy_deployment_id');
            $table->dropConstrainedForeignId('deploy_requested_by');
            $table->dropColumn(['deploy_requested_at', 'deploy_skipped_reason', 'deploy_resolved_at']);
        });
    }
};
