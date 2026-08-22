<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_installation_user', function (Blueprint $table): void {
            $table->foreignUuid('github_installation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('last_verified_at');
            $table->timestampsTz();

            $table->primary(['github_installation_id', 'user_id']);
            $table->index(['user_id', 'last_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_installation_user');
    }
};
