<?php

declare(strict_types=1);

use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('repository_provider', 32)->default('github');
            $table->string('repository_url', 2048);
            $table->string('repository_full_name', 255)->nullable();
            $table->string('branch', 255)->default('main');
            $table->string('default_domain', 255)->unique();
            $table->string('status', 32)->default(ProjectStatus::Draft->value);
            $table->string('runtime_status', 32)->default(RuntimeStatus::NotDeployed->value);
            $table->unsignedSmallInteger('detected_port')->nullable();
            $table->timestampTz('last_deployed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['user_id', 'runtime_status']);
            $table->index(['repository_provider', 'repository_full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
