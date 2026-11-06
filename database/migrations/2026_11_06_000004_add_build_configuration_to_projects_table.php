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
            $table->unsignedInteger('configured_port')->nullable()->after('detected_port');
            $table->string('build_command', 1000)->nullable()->after('configured_port');
            $table->string('start_command', 1000)->nullable()->after('build_command');
            $table->string('root_directory', 255)->nullable()->after('start_command');
            $table->timestampTz('config_changed_at')->nullable()->after('root_directory');
            $table->boolean('auto_deploy')->default(false)->after('config_changed_at');

            // Push webhook: projects opted in for one repository.
            $table->index(['github_repository_id', 'auto_deploy']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['github_repository_id', 'auto_deploy']);
            $table->dropColumn([
                'configured_port',
                'build_command',
                'start_command',
                'root_directory',
                'config_changed_at',
                'auto_deploy',
            ]);
        });
    }
};
