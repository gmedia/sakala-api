<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_nodes', function (Blueprint $table): void {
            // Reported by heartbeat metadata; null until the node has been seen.
            $table->unsignedSmallInteger('protocol_version')->nullable()->after('auth_status');
            // Control-plane intent, distinct from the agent-reported `status`.
            $table->string('desired_state', 32)->default('active')->after('protocol_version');
        });
    }

    public function down(): void
    {
        Schema::table('agent_nodes', function (Blueprint $table): void {
            $table->dropColumn(['protocol_version', 'desired_state']);
        });
    }
};
