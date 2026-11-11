<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner of projects. `personal_user_id` is set only on a personal workspace
 * and is unique, so the database keeps every user to one personal workspace.
 * A limit profile in use cannot be deleted from under a workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('slug', 60)->unique();
            $table->string('type', 16);
            $table->foreignId('personal_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('limit_profile_id')->nullable()->constrained('limit_profiles')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
