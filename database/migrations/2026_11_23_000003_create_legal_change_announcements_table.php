<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per user and announced version, written when the email is queued.
 * The unique key is what keeps `legal:announce-change` from mailing anyone
 * twice, however often it runs. It goes when the account goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_change_announcements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 32);
            $table->string('version', 64);
            $table->timestamp('queued_at');

            // Also serves the per-user NOT EXISTS that finds who is still waiting.
            $table->unique(['user_id', 'document', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_change_announcements');
    }
};
