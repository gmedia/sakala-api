<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Durable idempotency record for admin operations on a runtime node
     * (drain, resume). Kept separate from project_control_requests, which is
     * project-scoped with a NOT NULL project foreign key.
     */
    public function up(): void
    {
        Schema::create('agent_node_control_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('agent_node_id')->constrained('agent_nodes')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('idempotency_key', 191)->unique();
            $table->string('actor_type', 64);
            $table->unsignedBigInteger('actor_id');
            $table->text('reason');
            $table->foreignUuid('agent_command_id')->nullable()->constrained('agent_commands')->nullOnDelete();
            $table->json('response_context')->nullable();
            $table->timestampsTz();

            $table->index(['agent_node_id', 'action']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_node_control_requests');
    }
};
