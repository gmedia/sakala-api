<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('onboarding_completed_at');
        });

        DB::statement(
            'CREATE INDEX deployments_created_at_requested_by_index
             ON deployments (created_at, requested_by)
             WHERE requested_by IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['onboarding_completed_at']);
        });

        DB::statement(
            'DROP INDEX IF EXISTS deployments_created_at_requested_by_index'
        );
    }
};
