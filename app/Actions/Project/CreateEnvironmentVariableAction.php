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
            $variable = EnvironmentVariable::create([
                'project_id' => $data->projectId,
                'key' => $data->key,
                'encrypted_value' => $data->value,
                'is_secret' => $data->isSecret,
            ]);

            Project::query()->whereKey($data->projectId)->update(['config_changed_at' => now()]);

            return $variable;
        });
    }
}
