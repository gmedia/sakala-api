<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idle sleep per workspace: null follows SAKALA_PROJECT_IDLE_SLEEP_MINUTES,
 * 0 never puts the workspace's projects to sleep, and N sleeps them after N
 * minutes without a request. Existing profiles follow the global value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('idle_sleep_minutes')->nullable()->after('max_builds_per_hour');
        });
    }

    public function down(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn('idle_sleep_minutes');
        });
    }
};
