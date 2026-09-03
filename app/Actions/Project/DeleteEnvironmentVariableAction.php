<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Models\EnvironmentVariable;

final class DeleteEnvironmentVariableAction
{
    public function handle(EnvironmentVariable $environmentVariable): void
    {
        $environmentVariable->delete();
    }
}
