<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person in a workspace. `(user_id, role)` serves "my workspaces"
 * and the count of teams a user owns; the unique pair serves every policy check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
