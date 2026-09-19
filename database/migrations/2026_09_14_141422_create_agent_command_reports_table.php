<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only events and logs reported for commands that have no
     * deployment (InspectProject, CleanupRuntime, DrainNode, ResumeNode).
     * Deployment-scoped reports stay in deployment_events/deployment_logs so
     * the per-deployment sequence contract is untouched.
     */
    public function up(): void
    {
        Schema::create('agent_command_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('agent_command_id')->constrained('agent_commands')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('kind', 8);
            $table->string('level', 16)->nullable();
            $table->string('stream', 16)->nullable();
            $table->string('type', 64)->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->string('idempotency_key', 64)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['agent_command_id', 'sequence']);
            $table->unique(['agent_command_id', 'idempotency_key']);
            $table->index(['agent_command_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_command_reports');
    }
};
