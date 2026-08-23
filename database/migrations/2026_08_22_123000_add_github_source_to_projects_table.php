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
            $table->foreignUuid('github_installation_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->unsignedBigInteger('github_repository_id')->nullable()->after('github_installation_id');
            $table->index(['github_installation_id', 'github_repository_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['github_installation_id', 'github_repository_id']);
            $table->dropConstrainedForeignId('github_installation_id');
            $table->dropColumn('github_repository_id');
        });
    }
};
