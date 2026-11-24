<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled jobs of a project (ADR-022). The API is the only scheduler:
 * `schedules:dispatch` turns each due slot of an enabled schedule into one
 * `schedule_runs` row, and a run that may start into one RunJob command.
 *
 * Times are stored in UTC; `timezone` only decides how the cron expression is
 * read. `next_run_at` is the next slot the dispatcher has not handled yet.
 *
 * `schedule_runs (schedule_id, scheduled_for)` is unique, so two dispatchers
 * can never record the same slot twice; a manual run ("Run now") has no slot
 * (`scheduled_for` null), which the unique index does not compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('command', 1000);
            $table->string('cron_expression', 100);
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('timeout_seconds');
            $table->boolean('enabled');
            $table->timestampTz('next_run_at')->nullable();
            $table->string('health', 16);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampTz('paused_at')->nullable();
            $table->string('pause_reason', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // The dispatcher: enabled schedules whose next slot is due.
            $table->index(['enabled', 'next_run_at']);
            // The project's list.
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('schedule_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('project_schedules')->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->timestampTz('scheduled_for')->nullable();
            $table->string('status', 16);
            $table->string('reason', 32)->nullable();
            $table->foreignUuid('agent_command_id')->nullable()->constrained('agent_commands')->nullOnDelete();
            $table->foreignUuid('deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->string('command', 1000);
            $table->unsignedSmallInteger('timeout_seconds');
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->boolean('oom_killed')->nullable();
            $table->boolean('timed_out')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 1000)->nullable();
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // At most one run per slot, whatever runs the dispatcher.
            $table->unique(['schedule_id', 'scheduled_for']);
            // History of one schedule, newest first, and its open run.
            $table->index(['schedule_id', 'created_at']);
            $table->index(['schedule_id', 'status']);
            // Open runs of a workspace (through its projects) and waiting runs.
            $table->index(['status', 'project_id']);
            // A RunJob report finds its run.
            $table->unique('agent_command_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_runs');
        Schema::dropIfExists('project_schedules');
    }
};
