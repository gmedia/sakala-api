<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the rest of the schema needs for managed buckets (ADR-021):
 *
 * - `agent_commands.bucket_id`: the bucket a bucket command acts on.
 * - `projects.bucket_fingerprint`: DeploymentConfigFingerprint of the
 *   attached bucket's credentials, null without one, so attaching,
 *   detaching, and rotating ask for a redeploy.
 * - `limit_profiles.max_buckets` and `max_bucket_storage_mb`: null follows
 *   the global defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->foreignUuid('bucket_id')->nullable()->after('database_id')->constrained('buckets')->nullOnDelete();
            // Open commands of one bucket, before creating another.
            $table->index(['bucket_id', 'status']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('bucket_fingerprint', 64)->nullable()->after('database_fingerprint');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('max_buckets')->nullable()->after('max_database_storage_mb');
            $table->unsignedInteger('max_bucket_storage_mb')->nullable()->after('max_buckets');
        });
    }

    public function down(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn(['max_buckets', 'max_bucket_storage_mb']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('bucket_fingerprint');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['bucket_id', 'status']);
            $table->dropConstrainedForeignId('bucket_id');
        });
    }
};
