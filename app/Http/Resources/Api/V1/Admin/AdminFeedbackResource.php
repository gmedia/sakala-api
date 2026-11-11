<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\Feedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Feedback
 *
 * @property Feedback $resource
 */
final class AdminFeedbackResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $feedback = $this->resource;
        $user = $feedback->user;
        $project = $feedback->project_id === null ? null : $feedback->project;

        return [
            'id' => $feedback->id,
            /** `general`, `bug`, `feature_request`, `experience`, or `performance`. */
            'category' => $feedback->category->value,
            /** `open`, `acknowledged`, or `resolved`. */
            'status' => $feedback->status->value,
            /** Written by the user: show it as text, not HTML. */
            'message' => $feedback->message,
            /** Whether the user agreed to be contacted about it. */
            'consent' => $feedback->consent,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
            ],
            'project' => $project === null ? null : [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'deleted' => $project->trashed(),
            ],
            'deployment_id' => $feedback->deployment_id,
            'created_at' => $feedback->created_at?->toAtomString(),
            'updated_at' => $feedback->updated_at?->toAtomString(),
        ];
    }
}
