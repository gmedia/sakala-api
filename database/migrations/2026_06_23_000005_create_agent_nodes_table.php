<?php

declare(strict_types=1);

use App\Enums\AgentNodeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agent_id', 120)->unique();
            $table->string('name', 120)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('token_prefix', 16)->nullable()->index();
            $table->string('status', 32)->default(AgentNodeStatus::Offline->value);
            $table->string('hostname', 255)->nullable();
            $table->string('runtime_network', 120)->default('sakala-runtime');
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_nodes');
    }
};
