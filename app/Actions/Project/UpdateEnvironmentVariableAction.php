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
            $valueChanged = $value !== null && $value !== $variable->encrypted_value;

            $variable->update(array_filter([
                'encrypted_value' => $value,
                'is_secret' => $isSecret,
            ], static fn (mixed $attribute): bool => $attribute !== null));

            // `is_secret` only changes who may read it, not what a container gets.
            if ($valueChanged) {
                Project::query()->whereKey($variable->project_id)->update(['config_changed_at' => now()]);
            }

            return $variable;
        });
    }
}
