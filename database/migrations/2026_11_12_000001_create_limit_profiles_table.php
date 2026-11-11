<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable resource limits an admin attaches to workspaces ("Pilot", "Kelas").
 * A workspace without one uses the default for its type from config, so the
 * table starts empty and nothing changes until a profile is attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('max_projects');
            $table->unsignedInteger('max_active_containers');
            $table->unsignedInteger('max_active_deployments');
            $table->unsignedInteger('max_memory_mb');
            $table->unsignedInteger('max_cpu_millis');
            // Reserved for the build rate limit (sakala-api #124); null means no limit.
            $table->unsignedInteger('max_builds_per_hour')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_profiles');
    }
};
