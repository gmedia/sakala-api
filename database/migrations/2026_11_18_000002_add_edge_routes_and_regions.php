<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sakala Edge (ADR-019 stage 2) and regions (stage 3).
 *
 * - `agent_nodes.region_override` and `edge_address_override` are what an
 *   admin set; a node also reports both in its heartbeat metadata
 *   (`metadata.runtime`), and the admin value wins. Null means "use the
 *   reported one".
 * - `projects.region` and `limit_profiles.default_region` choose where a
 *   project is placed; null means any region.
 * - `edge_tokens` holds the read-only credentials of Edge instances, as an
 *   HMAC like agent tokens. No index beyond the unique hash: every request
 *   looks a token up by its hash, and there are only a handful of rows.
 *
 * Lengths are literal on purpose (RegionSlug::MAX_LENGTH,
 * EdgeAddress::MAX_LENGTH, EdgeToken): a migration must not change when a
 * constant does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_nodes', function (Blueprint $table): void {
            $table->string('region_override', 32)->nullable()->after('runtime_network');
            $table->string('edge_address_override', 255)->nullable()->after('region_override');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('region', 32)->nullable()->after('replicas');
        });

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->string('default_region', 32)->nullable()->after('max_replicas');
        });

        Schema::create('edge_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100)->unique();
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_tokens');

        Schema::table('limit_profiles', function (Blueprint $table): void {
            $table->dropColumn('default_region');
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('region');
        });

        Schema::table('agent_nodes', function (Blueprint $table): void {
            $table->dropColumn(['region_override', 'edge_address_override']);
        });
    }
};
