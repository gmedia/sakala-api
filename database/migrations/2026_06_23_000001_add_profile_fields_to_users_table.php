<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default(UserRole::User->value)->index();
            $table->string('avatar_url', 2048)->nullable();
            $table->string('onboarding_source', 32)->nullable()->index();
            $table->timestampTz('onboarding_completed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'avatar_url',
                'onboarding_source',
                'onboarding_completed_at',
                'last_login_at',
            ]);
        });
    }
};
