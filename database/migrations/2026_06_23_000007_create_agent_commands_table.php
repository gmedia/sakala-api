<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('deployment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('agent_node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->string('status', 32)->default(AgentCommandStatus::Pending->value);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'available_at', 'created_at']);
            $table->index(['agent_node_id', 'status', 'available_at']);
            $table->index(['deployment_id', 'status']);
            $table->index(['project_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
