<?php

declare(strict_types=1);

namespace App\Actions\Feedback;

use App\Data\Feedback\SubmitFeedbackData;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SubmitFeedbackAction
{
    public function handle(User $user, SubmitFeedbackData $data): Feedback
    {
        $project = null;
        $deployment = null;

        if ($data->projectId !== null) {
            /** @var Project|null $project */
            $project = Project::find($data->projectId);

            if ($project !== null) {
                Gate::forUser($user)->authorize('view', $project);
            }
        }

        if ($data->deploymentId !== null) {
            /** @var Deployment|null $deployment */
            $deployment = Deployment::with('project')->find($data->deploymentId);

            if ($deployment !== null && $deployment->project !== null) {
                Gate::forUser($user)->authorize('view', $deployment->project);
            }
        }

        if ($data->projectId !== null && $data->deploymentId !== null) {
            if ($deployment !== null && $deployment->project_id !== $data->projectId) {
                throw ValidationException::withMessages([
                    'deployment_id' => ['The deployment does not belong to the specified project.'],
                ]);
            }
        }

        $isDuplicate = Feedback::query()
            ->where('user_id', $user->id)
            ->where('category', $data->category)
            ->where('message', $data->message)
            ->where('project_id', $data->projectId)
            ->where('deployment_id', $data->deploymentId)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($isDuplicate) {
            abort(409, 'Duplicate feedback submission detected. Please wait before submitting again.');
        }

        return Feedback::create([
            'user_id' => $user->id,
            'project_id' => $data->projectId,
            'deployment_id' => $data->deploymentId,
            'category' => $data->category,
            'message' => $data->message,
            'consent' => $data->consent,
        ]);
    }
}
