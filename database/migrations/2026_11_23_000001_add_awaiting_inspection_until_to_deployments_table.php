<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `awaiting_inspection_until`: set while a queued deployment holds its
 * DeployProject command until the project's inspection reports the port
 * (DeploymentInspectionHold); the time after which the deployment fails
 * instead. Null for every other deployment, existing rows included.
 *
 * Indexed for the minute sweep, which reads only the few held rows.
 * Nullable, so no backfill and no rewrite of a populated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->timestampTz('awaiting_inspection_until')->nullable()->after('cancel_requested_at');
            $table->index('awaiting_inspection_until');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex(['awaiting_inspection_until']);
            $table->dropColumn('awaiting_inspection_until');
        });
    }
};
