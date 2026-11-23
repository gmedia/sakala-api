<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rollback, the auto-deploy pause after it, who triggered a deployment, and
 * the user restart:
 *
 * - `deployments.triggered_by_name`: the pusher's GitHub name for `webhook`
 *   deployments. Null for every existing row and for other triggers.
 * - `deployments.rollback_target_deployment_id`: the deployment a `rollback`
 *   deployment went back to.
 * - `deployments.settings_source_deployment_id`: the deployment whose recorded
 *   settings (root directory, build, start, and release commands, port) this
 *   one ran with instead of the project's. Null means the project's.
 * - `projects.auto_deploy_paused_at` and
 *   `projects.auto_deploy_paused_by_deployment_id`: set while auto-deploy is
 *   paused after a rollback.
 * - `projects.previous_active_deployment_id`: the deployment whose image the
 *   node keeps next to the live one (the agent's `previous` tag).
 * - `agent_commands (project_id, type, completed_at)`: the user restarts the
 *   metrics count and the open restart the project resource shows.
 *
 * The self and cross references are `ON DELETE SET NULL` and indexed, because
 * PostgreSQL looks every one of them up for each deployment
 * `projects:purge-deleted` deletes. Only nullable columns: no backfill and no
 * rewrite of a populated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->string('triggered_by_name', 255)->nullable()->after('trigger');
            $table->foreignUuid('rollback_target_deployment_id')->nullable()->after('triggered_by_name')->constrained('deployments')->nullOnDelete();
            $table->foreignUuid('settings_source_deployment_id')->nullable()->after('rollback_target_deployment_id')->constrained('deployments')->nullOnDelete();
            $table->index('rollback_target_deployment_id');
            $table->index('settings_source_deployment_id');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->timestampTz('auto_deploy_paused_at')->nullable()->after('auto_deploy');
            $table->foreignUuid('auto_deploy_paused_by_deployment_id')->nullable()->after('auto_deploy_paused_at')->constrained('deployments')->nullOnDelete();
            $table->foreignUuid('previous_active_deployment_id')->nullable()->after('active_deployment_id')->constrained('deployments')->nullOnDelete();
            $table->index('auto_deploy_paused_by_deployment_id');
            $table->index('previous_active_deployment_id');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->index(['project_id', 'type', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'type', 'completed_at']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['auto_deploy_paused_by_deployment_id']);
            $table->dropIndex(['previous_active_deployment_id']);
            $table->dropConstrainedForeignId('auto_deploy_paused_by_deployment_id');
            $table->dropConstrainedForeignId('previous_active_deployment_id');
            $table->dropColumn('auto_deploy_paused_at');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['rollback_target_deployment_id']);
            $table->dropIndex(['settings_source_deployment_id']);
            $table->dropConstrainedForeignId('rollback_target_deployment_id');
            $table->dropConstrainedForeignId('settings_source_deployment_id');
            $table->dropColumn('triggered_by_name');
        });
    }
};
