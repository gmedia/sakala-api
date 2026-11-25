<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domains for projects (ADR-024). Each row is one claim of a name by
 * one project, with its own verification token. A name may be claimed by
 * several projects at once; at most one live claim of a name is `verified`,
 * enforced by a partial unique index so two checks passing at the same
 * moment cannot both win. Deleting is a soft delete; purging the project
 * removes its rows.
 *
 * `limit_profiles.max_custom_domains`: null follows the global default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('hostname', 253);
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('verification_token', 64);
            $table->json('check_reasons')->nullable();
            $table->string('routing_match', 16)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('next_check_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('failing_since')->nullable();
            $table->timestampTz('warned_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampTz('admin_disabled_at')->nullable();
            $table->string('admin_disabled_reason', 500)->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            // A project's domains, and the workspace count through projects.
            $table->index(['project_id', 'status']);
            // custom-domains:check picks due claims.
            $table->index('next_check_at');
            // Claims of one name: taken checks, releasing disabled claims, admin search.
            $table->index('hostname');
        });

        DB::statement(
            "CREATE UNIQUE INDEX project_domains_hostname_verified_unique
             ON project_domains (hostname)
             WHERE status = 'verified' AND deleted_at IS NULL"
        );

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('max_custom_domains')->nullable()->after('max_valkey_memory_mb');
        });
    }

    public function down(): void
    {
        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn('max_custom_domains');
        });

        DB::statement('DROP INDEX IF EXISTS project_domains_hostname_verified_unique');
        Schema::dropIfExists('project_domains');
    }
};
