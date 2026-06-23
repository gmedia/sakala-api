<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_command_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('stream', 16);
            $table->text('message');
            $table->timestampTz('recorded_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['deployment_id', 'sequence']);
            $table->index(['deployment_id', 'recorded_at']);
            $table->index(['agent_command_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_logs');
    }
};
