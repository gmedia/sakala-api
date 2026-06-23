<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('agent_node_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('status', 32)->default(DeploymentStatus::Queued->value);
            $table->string('trigger', 32)->default(DeploymentTrigger::Manual->value);
            $table->string('branch', 255);
            $table->char('commit_sha', 40)->nullable();
            $table->string('commit_message', 500)->nullable();
            $table->string('image_reference', 512)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['project_id', 'sequence']);
            $table->index(['project_id', 'created_at']);
            $table->index(['project_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['agent_node_id', 'status']);
            $table->index(['failure_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
