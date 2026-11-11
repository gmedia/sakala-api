<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accepting, declining, revoking, or replacing an invitation now closes the row
 * instead of deleting it, so the holder of the link learns why it stopped
 * working. One pending invitation per address per workspace stays the rule,
 * through a partial unique index, since closed rows for the same address may
 * pile up until they are pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->string('status', 16)->default('pending')->after('role');
            $table->timestampTz('closed_at')->nullable()->after('expires_at');
            $table->dropUnique(['workspace_id', 'email']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX workspace_invitations_pending_workspace_id_email_unique
             ON workspace_invitations (workspace_id, email)
             WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        DB::table('workspace_invitations')->where('status', '!=', 'pending')->delete();

        DB::statement('DROP INDEX IF EXISTS workspace_invitations_pending_workspace_id_email_unique');

        Schema::table('workspace_invitations', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'email']);
            $table->dropColumn(['status', 'closed_at']);
        });
    }
};
