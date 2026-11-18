<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

final class DeleteEnvironmentVariableAction
{
    public function handle(EnvironmentVariable $environmentVariable): void
    {
        DB::transaction(function () use ($environmentVariable): void {
            // The project row first, so concurrent variable changes recompute
            // the environment fingerprint one at a time.
            /** @var Project $project */
            $project = Project::query()->whereKey($environmentVariable->project_id)->lockForUpdate()->firstOrFail();

            $environmentVariable->delete();

            $project->refreshEnvironmentFingerprint();
            $project->config_changed_at = now()->toImmutable();
            $project->save();
        });
    }
}
