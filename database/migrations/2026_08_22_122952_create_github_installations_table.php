<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('github_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('github_installation_id')->unique();
            $table->unsignedBigInteger('account_id');
            $table->string('account_login', 255);
            $table->string('account_type', 32);
            $table->string('repository_selection', 32);
            $table->json('permissions')->nullable();
            $table->string('status', 32);
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_installations');
    }
};
