<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the rest of the schema needs for managed databases:
 *
 * - `agent_commands.database_id`: the database a database command acts on.
 * - `projects.database_fingerprint`: DeploymentConfigFingerprint of the
 *   attached database's connection values, null without one, so attaching,
 *   detaching, and rotating ask for a redeploy.
 * - `limit_profiles.max_databases` and `max_database_storage_mb`: null follows
 *   the global defaults.
 * - `workspaces.database_storage_warned_at`: when the 80 % storage notice was
 *   sent, cleared below 80 %, so the notice is sent once per crossing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->foreignUuid('database_id')->nullable()->after('deployment_id')->constrained('databases')->nullOnDelete();
            // Open commands of one database, before creating another.
            $table->index(['database_id', 'status']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('database_fingerprint', 64)->nullable()->after('environment_fingerprint');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('max_databases')->nullable()->after('default_region');
            $table->unsignedInteger('max_database_storage_mb')->nullable()->after('max_databases');
        });

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->timestampTz('database_storage_warned_at')->nullable()->after('limit_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('database_storage_warned_at');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn(['max_databases', 'max_database_storage_mb']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('database_fingerprint');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['database_id', 'status']);
            $table->dropConstrainedForeignId('database_id');
        });
    }
};
