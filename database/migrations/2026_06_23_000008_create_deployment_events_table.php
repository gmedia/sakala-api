<?php

declare(strict_types=1);

use App\Enums\DeploymentEventLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_command_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('level', 16)->default(DeploymentEventLevel::Info->value);
            $table->string('type', 64)->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['deployment_id', 'sequence']);
            $table->index(['deployment_id', 'occurred_at']);
            $table->index(['agent_command_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_events');
    }
};
