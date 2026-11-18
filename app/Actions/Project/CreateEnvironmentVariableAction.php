<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\EnvironmentVariableData;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

final class CreateEnvironmentVariableAction
{
    public function handle(EnvironmentVariableData $data): EnvironmentVariable
    {
        return DB::transaction(function () use ($data): EnvironmentVariable {
            // The project row first, so concurrent variable changes recompute
            // the environment fingerprint one at a time.
            /** @var Project $project */
            $project = Project::query()->whereKey($data->projectId)->lockForUpdate()->firstOrFail();

            $variable = EnvironmentVariable::create([
                'project_id' => $project->id,
                'key' => $data->key,
                'encrypted_value' => $data->value,
                'is_secret' => $data->isSecret,
            ]);

            $project->refreshEnvironmentFingerprint();
            $project->config_changed_at = now()->toImmutable();
            $project->save();

            return $variable;
        });
    }
}
