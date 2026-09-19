<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            // Deadline for a claimed command to reach a terminal state; set on
            // claim from the command's execution timeout plus grace. Distinct
            // from expires_at, which is the availability deadline while Pending.
            $table->timestampTz('lease_expires_at')->nullable()->after('expires_at');
            // Only query path: the expiry sweep over Claimed/Running commands.
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn('lease_expires_at');
        });
    }
};
