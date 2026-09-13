<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployment_events', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('occurred_at');
            $table->string('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['agent_command_id', 'idempotency_key']);
        });

        Schema::table('deployment_logs', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->after('recorded_at');
            $table->string('payload_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['agent_command_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('deployment_events', function (Blueprint $table): void {
            $table->dropUnique(['agent_command_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });

        Schema::table('deployment_logs', function (Blueprint $table): void {
            $table->dropUnique(['agent_command_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'payload_hash']);
        });
    }
};
