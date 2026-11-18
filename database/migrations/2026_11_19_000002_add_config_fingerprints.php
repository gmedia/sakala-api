<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fingerprints behind `redeploy_required` (DeploymentConfigFingerprint).
 * `projects.environment_fingerprint` covers the project's environment
 * variables and is kept current by the environment variable actions;
 * `deployments.config_fingerprint` is the deploy-relevant configuration a
 * deployment was created with. Both are nullable without a backfill: a
 * project gets its environment fingerprint at its next deployment, and an
 * older deployment falls back to comparing `projects.config_changed_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('environment_fingerprint', 64)->nullable()->after('config_changed_at');
        });

        Schema::table('deployments', function (Blueprint $table): void {
            $table->string('config_fingerprint', 64)->nullable()->after('replicas_ready');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('config_fingerprint');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('environment_fingerprint');
        });
    }
};
