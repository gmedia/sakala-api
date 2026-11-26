<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\ProjectProcessData;
use App\Models\Project;
use App\Models\ProjectProcess;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Project\WorkloadAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or changes a worker definition of a project (ADR-022). Like a
 * variable, a change applies from the next deployment and turns
 * `redeploy_required` on. Under the project row lock, so the count, the
 * worker total, and the processes fingerprint are decided one change at a
 * time. Whether the workspace may run the workers right now is decided again
 * when deploying (PilotRuntimeLimitService::checkActiveDeploymentLimit()).
 */
final class SaveProjectProcessAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
    ) {}

    public function create(Project $project, User $actor, ProjectProcessData $data): ProjectProcess
    {
        return DB::transaction(function () use ($project, $actor, $data): ProjectProcess {
            $locked = $this->lock($project);

            if ($locked->processes()->count() >= ProjectProcess::MAX_PER_PROJECT) {
                throw ValidationException::withMessages([
                    'name' => [sprintf('Satu proyek paling banyak punya %d worker. Hapus worker yang tidak dipakai dulu.', ProjectProcess::MAX_PER_PROJECT)],
                ]);
            }

            $process = new ProjectProcess(['project_id' => $locked->id, ...$data->attributes()]);
            $this->ensureWithinWorkerLimit($locked, $process);
            $process->save();

            $this->applied($locked);

            WorkloadAudit::record($actor, WorkloadAudit::PROCESS_CREATED, $locked, [
                'process_id' => $process->id,
                'name' => $process->name,
                'replicas' => $process->replicas,
            ]);

            return $process;
        });
    }

    public function update(ProjectProcess $process, User $actor, ProjectProcessData $data): ProjectProcess
    {
        return DB::transaction(function () use ($process, $actor, $data): ProjectProcess {
            $locked = $this->lock($process->project);

            /** @var ProjectProcess $current */
            $current = ProjectProcess::query()->whereKey($process->id)->lockForUpdate()->firstOrFail();
            $current->fill($data->attributes());
            $changes = $current->getDirty();

            if ($changes === []) {
                return $current;
            }

            $before = array_intersect_key($current->getOriginal(), $changes);
            $this->ensureWithinWorkerLimit($locked, $current);
            $current->save();

            $this->applied($locked);

            WorkloadAudit::record($actor, WorkloadAudit::PROCESS_UPDATED, $locked, [
                'process_id' => $current->id,
                'name' => $current->name,
                'from' => $before,
                'to' => $changes,
            ]);

            return $current;
        });
    }

    public function delete(ProjectProcess $process, User $actor): void
    {
        DB::transaction(function () use ($process, $actor): void {
            $locked = $this->lock($process->project);

            ProjectProcess::query()->whereKey($process->id)->delete();

            $this->applied($locked);

            WorkloadAudit::record($actor, WorkloadAudit::PROCESS_DELETED, $locked, [
                'process_id' => $process->id,
                'name' => $process->name,
            ]);
        });
    }

    private function lock(Project $project): Project
    {
        /** @var Project */
        return Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * The project's own worker containers, with `$changed` as it is about to
     * be saved, must fit the workspace's `max_worker_containers`: a
     * definition no deployment could run is refused now, not at deploy time.
     */
    private function ensureWithinWorkerLimit(Project $project, ProjectProcess $changed): void
    {
        $workspace = $project->workspace;

        if ($workspace === null) {
            return;
        }

        $max = $this->limits->limitsFor($workspace)->max_worker_containers;
        $others = (int) $project->processes()->whereKeyNot($changed->id)->sum('replicas');
        $total = $others + $changed->replicas;

        if ($total > $max) {
            throw ValidationException::withMessages([
                'replicas' => [$max === 0
                    ? 'Workspace ini belum boleh menjalankan worker. Simpan dengan 0 replika, atau minta admin Sakala membuka batasnya.'
                    : "Workspace ini paling banyak menjalankan {$max} worker sekaligus, sedangkan total replika worker proyek ini akan menjadi {$total}."],
            ]);
        }
    }

    /** The definitions changed: the next deployment differs from the serving one. */
    private function applied(Project $project): void
    {
        $project->refreshProcessesFingerprint();
        $project->config_changed_at = now()->toImmutable();
        $project->save();
    }
}
