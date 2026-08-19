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
        Schema::table('deployments', function (Blueprint $table) {
            // Add idempotency_key column
            $table->string('idempotency_key', 255)->nullable()->after('requested_by');

            $table->unique([
                'project_id',
                'requested_by',
                'idempotency_key',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            //
            $table->dropUnique([
                'project_id',
                'requested_by',
                'idempotency_key',
            ]);

            $table->dropColumn('idempotency_key');
        });
    }
};
