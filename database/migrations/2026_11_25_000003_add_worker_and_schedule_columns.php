<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the rest of the schema needs for worker processes and scheduled jobs
 * (ADR-022):
 *
 * - `deployments.worker_containers`: worker containers the deployment runs
 *   next to its replicas, fixed at creation like `replicas`; they count
 *   against `max_active_containers` and `max_worker_containers`.
 * - `deployments.crashed_processes`: worker processes of the deployment the
 *   node reports as crashing, keyed by name; null or absent when none.
 * - `projects.processes_fingerprint`: DeploymentConfigFingerprint of the
 *   process definitions, null without any, so a change asks for a redeploy.
 * - `deployment_logs.process`: the worker name or `job` the line came from;
 *   null for the deployment's own lines (build, release command, web).
 * - `agent_commands.process_log_bytes`: bytes reported per process, so each
 *   worker gets its own `max_total_bytes` budget.
 * - `limit_profiles` fields; null follows the global defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('worker_containers')->default(0)->after('replicas');
            $table->json('crashed_processes')->nullable()->after('crash_notified_at');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('processes_fingerprint', 64)->nullable()->after('bucket_fingerprint');
        });

        Schema::table('deployment_logs', function (Blueprint $table): void {
            $table->string('process', 30)->nullable()->after('stream');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->json('process_log_bytes')->nullable()->after('reported_log_bytes');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('max_worker_containers')->nullable()->after('max_bucket_storage_mb');
            $table->unsignedInteger('max_schedules')->nullable()->after('max_worker_containers');
            $table->unsignedInteger('min_schedule_interval_minutes')->nullable()->after('max_schedules');
            $table->unsignedInteger('max_concurrent_job_runs')->nullable()->after('min_schedule_interval_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn(['max_worker_containers', 'max_schedules', 'min_schedule_interval_minutes', 'max_concurrent_job_runs']);
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropColumn('process_log_bytes');
        });

        Schema::table('deployment_logs', function (Blueprint $table): void {
            $table->dropColumn('process');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('processes_fingerprint');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn(['worker_containers', 'crashed_processes']);
        });
    }
};
