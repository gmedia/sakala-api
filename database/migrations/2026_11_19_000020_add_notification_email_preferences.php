<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email as a second notification channel. `users.notification_email_preferences`
 * keeps one boolean per category that can be emailed; null means the defaults.
 * `project_alert_mail_windows` remembers when a user was last emailed about one
 * kind of alert on one project, and how many were held back since, so repeated
 * failures become one email per window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('notification_email_preferences')->nullable()->after('notification_preferences');
        });

        Schema::create('project_alert_mail_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('alert', 40);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->timestamps();

            // The lookup key of every alert email, and what keeps two workers from both sending.
            $table->unique(['user_id', 'project_id', 'alert']);
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_alert_mail_windows');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('notification_email_preferences');
        });
    }
};
