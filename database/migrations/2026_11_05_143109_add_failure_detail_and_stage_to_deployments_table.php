<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->string('failure_detail', 500)->nullable()->after('failure_summary');
            $table->string('failure_stage', 32)->nullable()->after('failure_detail');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn(['failure_detail', 'failure_stage']);
        });
    }
};
