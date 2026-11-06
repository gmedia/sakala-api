<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting an account removes the user while its soft-deleted projects wait for
 * their retention window and for a node to stop their workloads. Those rows are
 * the only ones allowed to lose their owner: the foreign key stays RESTRICT, so a
 * live project still blocks the deletion of its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Skipped once ownerless rows exist: they only leave through the purge, and
     * the owner they had is gone, so there is nothing to restore them to.
     */
    public function down(): void
    {
        if (DB::table('projects')->whereNull('user_id')->exists()) {
            return;
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
