<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basic per-project metrics from heartbeat `metadata.workloads.metrics`
 * (#128). `project_metric_minutes` holds one row per replica per minute and
 * lives SAKALA_METRICS_MINUTE_RETENTION_HOURS; `projects:roll-up-metrics`
 * folds it into `project_metric_hours`, kept
 * SAKALA_METRICS_HOURLY_RETENTION_DAYS. Averages are stored as a sum and a
 * sample count so several samples of one minute (or hour) merge exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_metric_minutes', function (Blueprint $table): void {
            $this->metricColumns($table);
        });

        Schema::create('project_metric_hours', function (Blueprint $table): void {
            $this->metricColumns($table);
            // Restarts within the hour, from the cumulative counter of the
            // minute rows, counter resets included.
            $table->unsignedInteger('restarts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_metric_hours');
        Schema::dropIfExists('project_metric_minutes');
    }

    private function metricColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
        $table->foreignUuid('deployment_id')->constrained()->cascadeOnDelete();
        $table->unsignedSmallInteger('replica');
        // Start of the minute (or hour), UTC.
        $table->timestampTz('bucket');
        $table->unsignedInteger('sample_count');
        $table->double('cpu_percent_sum');
        $table->double('cpu_percent_max');
        $table->unsignedBigInteger('memory_bytes_sum');
        $table->unsignedBigInteger('memory_bytes_max');
        // From the latest sample; null means the container has no limit.
        $table->unsignedBigInteger('memory_limit_bytes')->nullable();
        // Cumulative container restarts: the highest in the minute, the
        // latest minute's value in the hour.
        $table->unsignedInteger('restart_count');
        $table->timestampTz('last_sampled_at');

        // The upsert key; its leading deployment_id also serves the cascade
        // when a deployment is purged.
        $table->unique(['deployment_id', 'replica', 'bucket']);
        // GET app/projects/{project}/metrics reads one project over a range.
        $table->index(['project_id', 'bucket']);
        // Roll-up reads and prune deletes by time across every project.
        $table->index('bucket');
    }
};
