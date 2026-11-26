<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the rest of the schema needs for managed Valkey (ADR-023):
 *
 * - `agent_commands.valkey_id`: the store a Valkey command acts on.
 * - `projects.valkey_fingerprint`: DeploymentConfigFingerprint of the
 *   attached store's connection, null without one, so attaching, detaching,
 *   and rotating ask for a redeploy.
 * - `limit_profiles.max_valkeys` and `max_valkey_memory_mb`: null follows
 *   the global defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->foreignUuid('valkey_id')->nullable()->after('bucket_id')->constrained('valkeys')->nullOnDelete();
            // Open commands of one store, before sending another.
            $table->index(['valkey_id', 'status']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('valkey_fingerprint', 64)->nullable()->after('bucket_fingerprint');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('max_valkeys')->nullable()->after('max_bucket_storage_mb');
            $table->unsignedInteger('max_valkey_memory_mb')->nullable()->after('max_valkeys');
        });
    }

    public function down(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn(['max_valkeys', 'max_valkey_memory_mb']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('valkey_fingerprint');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['valkey_id', 'status']);
            $table->dropConstrainedForeignId('valkey_id');
        });
    }
};
