<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Data\Auth\AccountExportData;
use App\Http\Resources\Api\V1\Notification\NotificationResource;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Feedback;
use App\Models\GithubInstallation;
use App\Models\LegalAcceptance;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Every field is named here on purpose. Serializing a model would carry along
 * whatever gets added to it later, and these models sit next to OAuth tokens and
 * secret values.
 *
 * @property AccountExportData $resource
 */
final class AccountExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $user = $data->user;

        return [
            'exported_at' => $data->exportedAt->toAtomString(),
            'max_items_per_collection' => $data->maxItems,
            /** @var list<string> */
            'truncated' => $data->truncated,
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'pending_email' => $user->pending_email,
                'email_verified_at' => $user->email_verified_at?->toAtomString(),
                'avatar_url' => $user->avatar_url,
                'role' => $user->role->value,
                'onboarding_source' => $user->onboarding_source?->value,
                'onboarding_role' => $user->onboarding_role?->value,
                'onboarding_completed_at' => $user->onboarding_completed_at?->toAtomString(),
                'notification_preferences' => $user->notificationPreferences()->preferences,
                'last_login_at' => $user->last_login_at?->toAtomString(),
                'created_at' => $user->created_at?->toAtomString(),
                'legal_acceptances' => $user->legalAcceptances->map(static fn (LegalAcceptance $acceptance): array => [
                    'document' => $acceptance->document->value,
                    'version' => $acceptance->version,
                    'action' => $acceptance->action->value,
                    'source' => $acceptance->source->value,
                    'occurred_at' => $acceptance->occurred_at->toAtomString(),
                ])->all(),
                'oauth_accounts' => $user->oauthAccounts->map(static fn (OAuthAccount $account): array => [
                    'provider' => $account->provider->value,
                    'provider_username' => $account->provider_username,
                    'avatar_url' => $account->avatar_url,
                ])->all(),
                'github_installations' => $user->githubInstallations->map(static fn (GithubInstallation $installation): array => [
                    'account_login' => $installation->account_login,
                    'account_type' => $installation->account_type,
                    'repository_selection' => $installation->repository_selection,
                    'status' => $installation->status->value,
                ])->all(),
                'workspaces' => $user->workspaces->map(static fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'type' => $workspace->type->value,
                    'role' => $workspace->membership?->role->value,
                    'joined_at' => $workspace->membership?->created_at?->toAtomString(),
                ])->all(),
            ],
            'projects' => $data->projects->map(static fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'repository_url' => $project->repository_url,
                'repository_full_name' => $project->repository_full_name,
                'branch' => $project->branch,
                'default_domain' => $project->default_domain,
                'status' => $project->status->value,
                'runtime_status' => $project->runtime_status->value,
                'build_command' => $project->build_command,
                'start_command' => $project->start_command,
                'root_directory' => $project->root_directory,
                'configured_port' => $project->configured_port,
                'auto_deploy' => $project->auto_deploy,
                'thumbnail_url' => $project->thumbnail_url,
                'created_at' => $project->created_at?->toAtomString(),
                'deleted_at' => $project->deleted_at?->toAtomString(),
            ])->all(),
            'deployments' => $data->deployments->map(static fn (Deployment $deployment): array => [
                'id' => $deployment->id,
                'project_id' => $deployment->project_id,
                'sequence' => $deployment->sequence,
                'status' => $deployment->status->value,
                'trigger' => $deployment->trigger->value,
                'branch' => $deployment->branch,
                'commit_sha' => $deployment->commit_sha,
                'commit_message' => $deployment->commit_message,
                'failure_code' => $deployment->failure_code,
                'failure_summary' => $deployment->failure_summary,
                'started_at' => $deployment->started_at?->toAtomString(),
                'finished_at' => $deployment->finished_at?->toAtomString(),
                'created_at' => $deployment->created_at?->toAtomString(),
            ])->all(),
            // Names and flags only: a value is never part of an export, secret or not.
            'environment_variables' => $data->environmentVariables->map(static fn (EnvironmentVariable $variable): array => [
                'project_id' => $variable->project_id,
                'name' => $variable->key,
                'is_secret' => $variable->is_secret,
            ])->all(),
            'notifications' => $data->notifications->map(static fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'category' => NotificationResource::payloadString($notification, 'category'),
                'title' => NotificationResource::payloadString($notification, 'title'),
                'message' => NotificationResource::payloadString($notification, 'message'),
                'project_id' => NotificationResource::payloadString($notification, 'project_id'),
                'deployment_id' => NotificationResource::payloadString($notification, 'deployment_id'),
                'read_at' => $notification->read_at?->toAtomString(),
                'created_at' => $notification->created_at?->toAtomString(),
            ])->all(),
            'feedback' => $data->feedback->map(static fn (Feedback $feedback): array => [
                'id' => $feedback->id,
                'category' => $feedback->category->value,
                'message' => $feedback->message,
                'project_id' => $feedback->project_id,
                'deployment_id' => $feedback->deployment_id,
                'consent' => $feedback->consent,
                'created_at' => $feedback->created_at?->toAtomString(),
            ])->all(),
        ];
    }
}
