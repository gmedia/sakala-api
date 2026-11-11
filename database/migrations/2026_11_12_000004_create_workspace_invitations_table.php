<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending invitations only: accepting, declining, or revoking deletes the row,
 * and the audit trail keeps what happened. The token is stored as a SHA-256
 * hash. One pending invitation per address per workspace; inviting again
 * replaces it. `email` is stored lowercased and serves "invitations for me".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['workspace_id', 'email']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
