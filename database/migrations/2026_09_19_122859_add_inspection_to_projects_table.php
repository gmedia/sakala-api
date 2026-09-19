<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            // Result of the last InspectProject command (protocol v4
            // ProjectInspection, including the raw railpack output).
            $table->json('inspection')->nullable()->after('detected_port');
            $table->string('inspection_status', 32)->nullable()->after('inspection');
            $table->string('inspection_error_code', 64)->nullable()->after('inspection_status');
            $table->timestampTz('inspected_at')->nullable()->after('inspection_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['inspection', 'inspection_status', 'inspection_error_code', 'inspected_at']);
        });
    }
};
