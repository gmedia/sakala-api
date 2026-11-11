<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->timestampTz('cancel_requested_at')->nullable()->after('cancelled_at');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->timestampTz('cancel_requested_at')->nullable()->after('lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('cancel_requested_at');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
