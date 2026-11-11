<?php

declare(strict_types=1);

use App\Enums\FeedbackStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns and indexes behind the admin console (sakala-console#97). Additive
 * only: existing code keeps running, and every existing feedback starts open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feedbacks', function (Blueprint $table): void {
            $table->string('status', 32)->default(FeedbackStatus::Open->value)->after('consent');
            // Admin feedback list: filter by status, newest first.
            $table->index(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            // Admin overview: new users and the daily growth series in a range.
            $table->index('created_at');
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            // Admin node detail: one node's command history, newest first.
            $table->index(['agent_node_id', 'created_at']);
        });

        Schema::table('audit_events', function (Blueprint $table): void {
            // Admin audit list without an actor, subject, or action filter.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('agent_commands', function (Blueprint $table): void {
            $table->dropIndex(['agent_node_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('feedbacks', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn('status');
        });
    }
};
