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
            // Resources the agent actually applied, reported on DeployProject completion.
            $table->json('applied_resources')->nullable()->after('effective_resources');
            // Agent committed the route but could not finish post-commit cleanup;
            // the control plane must stop superseded workloads explicitly.
            $table->boolean('finalization_deferred')->default(false)->after('applied_resources');
            $table->string('finalization_deferred_reason', 32)->nullable()->after('finalization_deferred');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn(['applied_resources', 'finalization_deferred', 'finalization_deferred_reason']);
        });
    }
};
