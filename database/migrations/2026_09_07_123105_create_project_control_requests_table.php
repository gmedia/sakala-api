<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_control_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignUuid('project_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('action', 32);

            $table->string('idempotency_key', 191)
                ->unique();

            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id');

            $table->text('reason')->nullable();

            $table->foreignUuid('agent_command_id')
                ->nullable()
                ->constrained('agent_commands')
                ->nullOnDelete();

            $table->json('response_context')->nullable();

            $table->timestampsTz();

            $table->index(['project_id', 'action']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_control_requests');
    }
};
