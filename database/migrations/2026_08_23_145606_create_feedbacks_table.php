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
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('deployment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            $table->text('message');
            $table->boolean('consent');
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['project_id', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['user_id', 'category', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
