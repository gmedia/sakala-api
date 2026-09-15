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
        Schema::table('deployments', function (Blueprint $table): void {
            $table->index(
                ['status', 'finished_at'],
                'deployments_status_finished_at_index',
            );
            $table->index(
                ['status', 'cancelled_at'],
                'deployments_status_cancelled_at_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropIndex('deployments_status_finished_at_index');
            $table->dropIndex('deployments_status_cancelled_at_index');
        });
    }
};
