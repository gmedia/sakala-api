<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\EnvironmentVariableData;
use App\Models\EnvironmentVariable;

final class CreateEnvironmentVariableAction
{
    public function handle(EnvironmentVariableData $data): EnvironmentVariable
    {
        return EnvironmentVariable::create([
            'project_id' => $data->projectId,
            'key' => $data->key,
            'encrypted_value' => $data->value,
            'is_secret' => $data->isSecret,
        ]);
    }
}
