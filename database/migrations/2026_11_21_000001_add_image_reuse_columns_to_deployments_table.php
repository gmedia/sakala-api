<?php

declare(strict_types=1);

use App\Enums\DeploymentBuildMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redeploy without rebuild (image reuse):
 *
 * - `build_mode`: DeploymentBuildMode, `built` for every existing row.
 * - `image_source_deployment_id`: the deployment that built the image this one
 *   asked to reuse. Indexed because PostgreSQL looks the column up for every
 *   deployment `projects:purge-deleted` deletes (`ON DELETE SET NULL`).
 * - `build_fallback_reason`: DeploymentBuildFallbackReason when the agent
 *   built instead.
 * - `build_fingerprint`: DeploymentConfigFingerprint::forBuild() at creation.
 *   Null for existing rows, which are therefore never reused.
 *
 * Only defaulted or nullable columns, so no backfill and no rewrite of a
 * populated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->string('build_mode', 16)->default(DeploymentBuildMode::Built->value)->after('image_reference');
            $table->foreignUuid('image_source_deployment_id')->nullable()->after('build_mode')->constrained('deployments')->nullOnDelete();
            $table->string('build_fallback_reason', 32)->nullable()->after('image_source_deployment_id');
            $table->string('build_fingerprint', 64)->nullable()->after('config_fingerprint');
            $table->index('image_source_deployment_id');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['image_source_deployment_id']);
            $table->dropConstrainedForeignId('image_source_deployment_id');
            $table->dropColumn(['build_mode', 'build_fallback_reason', 'build_fingerprint']);
        });
    }
};
