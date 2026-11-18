<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

final class UpdateEnvironmentVariableAction
{
    public function handle(EnvironmentVariable $variable, ?string $value, ?bool $isSecret): EnvironmentVariable
    {
        return DB::transaction(function () use ($variable, $value, $isSecret): EnvironmentVariable {
            // The project row first, so concurrent variable changes recompute
            // the environment fingerprint one at a time.
            /** @var Project $project */
            $project = Project::query()->whereKey($variable->project_id)->lockForUpdate()->firstOrFail();

            $variable->refresh();

            $valueChanged = $value !== null && $value !== $variable->encrypted_value;

            $variable->update(array_filter([
                'encrypted_value' => $value,
                'is_secret' => $isSecret,
            ], static fn (mixed $attribute): bool => $attribute !== null));

            $project->refreshEnvironmentFingerprint();

            // `is_secret` only changes who may read it, not what a container gets.
            if ($valueChanged) {
                $project->config_changed_at = now()->toImmutable();
            }

            $project->save();

            return $variable;
        });
    }
}
