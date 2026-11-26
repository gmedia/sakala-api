<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker process definitions of a project (ADR-022): containers of the same
 * image and variables as `web` that run another command, have no route, and
 * apply from the next deployment, which copies them into its DeployProject
 * payload. At most five per project (ProjectProcess::MAX_PER_PROJECT),
 * names unique per project. `memory_mb` and `cpu_millis` null follow the
 * deployment's resources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_processes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 30);
            $table->string('command', 1000);
            $table->unsignedSmallInteger('replicas');
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('cpu_millis')->nullable();
            $table->timestampsTz();

            // The project's list, ordered by name, and the name rule.
            $table->unique(['project_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_processes');
    }
};
