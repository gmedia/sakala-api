<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project replicas (load balancing on one node). `projects.replicas` is what
 * the owner asks for and applies from the next deployment; `deployments.replicas`
 * is what one deployment was asked to run, like `container_port`. The node
 * reports `replicas_desired` and `replicas_ready` per deployment in its
 * heartbeat, so a new deployment starts at "not reported yet" on its own.
 * `limit_profiles.max_replicas` null means one replica per project. Every
 * existing row keeps running exactly one container.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedSmallInteger('replicas')->default(1)->after('root_directory');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('replicas')->default(1)->after('container_port');
            $table->unsignedSmallInteger('replicas_desired')->nullable()->after('replicas');
            $table->unsignedSmallInteger('replicas_ready')->nullable()->after('replicas_desired');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_replicas')->nullable()->after('idle_sleep_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn('max_replicas');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn(['replicas', 'replicas_desired', 'replicas_ready']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('replicas');
        });
    }
};
