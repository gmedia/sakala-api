<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->unsignedBigInteger('reported_log_bytes')
                ->default(0)
                ->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropColumn('reported_log_bytes');
        });
    }
};
