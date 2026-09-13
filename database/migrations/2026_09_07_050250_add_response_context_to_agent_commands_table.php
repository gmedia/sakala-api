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
            $table->json('response_context')
                ->nullable()
                ->after('request_context');
        });
    }

    public function down(): void
    {
        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropColumn('response_context');
        });
    }
};
