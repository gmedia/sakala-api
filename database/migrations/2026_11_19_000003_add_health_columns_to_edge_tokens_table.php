<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edge health monitoring (docs/EDGE_API.md#pemantauan).
 *
 * - `last_used_at` becomes `last_seen_at`: the same "at most once a minute"
 *   timestamp, now written together with what the Edge reported.
 * - `last_version`, `last_applied_revision`, `last_status`: the headers of
 *   the last recorded request, null when the Edge sent none or a malformed
 *   one (older Edge versions send none).
 * - `status_since`: when `last_status` last changed value.
 * - `revision_mismatch_since`: set by `edge:check-health` when it first sees
 *   the applied revision differ from the current one, cleared on a match.
 * - `unhealthy_since`: the alert state of `edge:check-health`; null is
 *   healthy (or never evaluated).
 *
 * No index: the health check reads every row, and there are a handful.
 * Lengths are literal on purpose (EdgeContactData): a migration must not
 * change when a constant does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edge_tokens', function (Blueprint $table): void {
            $table->renameColumn('last_used_at', 'last_seen_at');
        });

        Schema::table('edge_tokens', function (Blueprint $table): void {
            $table->string('last_version', 64)->nullable()->after('last_seen_at');
            $table->string('last_applied_revision', 64)->nullable()->after('last_version');
            $table->string('last_status', 64)->nullable()->after('last_applied_revision');
            $table->timestamp('status_since')->nullable()->after('last_status');
            $table->timestamp('revision_mismatch_since')->nullable()->after('status_since');
            $table->timestamp('unhealthy_since')->nullable()->after('revision_mismatch_since');
        });
    }

    public function down(): void
    {
        Schema::table('edge_tokens', function (Blueprint $table): void {
            $table->dropColumn([
                'last_version',
                'last_applied_revision',
                'last_status',
                'status_since',
                'revision_mismatch_since',
                'unhealthy_since',
            ]);
        });

        Schema::table('edge_tokens', function (Blueprint $table): void {
            $table->renameColumn('last_seen_at', 'last_used_at');
        });
    }
};
