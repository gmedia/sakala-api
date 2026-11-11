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
            $environmentVariable->delete();

            Project::query()->whereKey($environmentVariable->project_id)->update(['config_changed_at' => now()]);
        });
    }
}
