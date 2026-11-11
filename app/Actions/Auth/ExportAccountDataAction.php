<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\AccountExportData;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Collects the account's own data, newest first, with a cap per collection that
 * the export reports when it is reached. Projects still inside their deletion
 * window count: the data exists, so the person may have a copy of it.
 */
final class ExportAccountDataAction
{
    public function handle(User $user): AccountExportData
    {
        $max = (int) config('sakala.account.export_max_items');
        $user->load(['legalAcceptances', 'oauthAccounts', 'githubInstallations']);

        $projects = Project::withTrashed()->where('user_id', $user->id)->latest('id')->limit($max + 1)->get();
        $projectIds = $projects->pluck('id')->all();
        $deployments = Deployment::query()->whereIn('project_id', $projectIds)->latest('id')->limit($max + 1)->get();
        $variables = EnvironmentVariable::query()->whereIn('project_id', $projectIds)->latest('id')->limit($max + 1)->get();
        $notifications = $user->notifications()->latest()->limit($max + 1)->get();
        $feedback = $user->feedbacks()->latest()->limit($max + 1)->get();

        $truncated = array_keys(array_filter([
            'projects' => $projects->count() > $max,
            'deployments' => $deployments->count() > $max,
            'environment_variables' => $variables->count() > $max,
            'notifications' => $notifications->count() > $max,
            'feedback' => $feedback->count() > $max,
        ]));

        return new AccountExportData(
            exportedAt: CarbonImmutable::now(),
            user: $user,
            projects: $projects->take($max),
            deployments: $deployments->take($max),
            environmentVariables: $variables->take($max),
            notifications: $notifications->take($max),
            feedback: $feedback->take($max),
            maxItems: $max,
            truncated: $truncated,
        );
    }
}
