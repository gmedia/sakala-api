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
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignUuid('active_deployment_id')
                ->nullable()
                ->after('detected_port')
                ->constrained('deployments')
                ->nullOnDelete();
            $table->index('active_deployment_id');
        });

        $this->backfillActiveDeployments();
    }

    /**
     * Point each project at its newest succeeded deployment unless that
     * workload was stopped on purpose. A project that went to `failed` only
     * because a redeploy failed has that deployment still serving, so it goes
     * back to `running`; the next heartbeat corrects it if that is wrong.
     */
    private function backfillActiveDeployments(): void
    {
        DB::table('projects')
            ->whereNull('deleted_at')
            ->where('runtime_status', '!=', 'stopped')
            ->select(['id', 'runtime_status'])
            ->chunkById(200, function ($projects): void {
                foreach ($projects as $project) {
                    $deploymentId = DB::table('deployments')
                        ->where('project_id', $project->id)
                        ->where('status', 'succeeded')
                        ->orderByDesc('sequence')
                        ->value('id');

                    if ($deploymentId === null) {
                        continue;
                    }

                    $stopped = DB::table('agent_commands')
                        ->where('deployment_id', $deploymentId)
                        ->whereIn('type', ['StopProject', 'SleepProject'])
                        ->where('status', 'Succeeded')
                        ->exists();

                    if ($stopped) {
                        continue;
                    }

                    DB::table('projects')->where('id', $project->id)->update([
                        'active_deployment_id' => $deploymentId,
                        'runtime_status' => $project->runtime_status === 'failed'
                            ? 'running'
                            : $project->runtime_status,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['active_deployment_id']);
            $table->dropConstrainedForeignId('active_deployment_id');
        });
    }
};
