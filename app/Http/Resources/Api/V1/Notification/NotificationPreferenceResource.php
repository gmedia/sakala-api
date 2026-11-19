<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notification;

use App\Data\Notification\NotificationPreferencesData;
use App\Enums\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property NotificationPreferencesData $resource
 */
final class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array{deployment_success: bool, deployment_failure: bool, project_issue: bool, account_security: bool, workspace: bool, email: array{deployment_success: bool, deployment_failure: bool, project_issue: bool}}
     */
    public function toArray(Request $request): array
    {
        $preferences = $this->resource->preferences;
        $email = $this->resource->email;

        return [
            /** @var bool */
            'deployment_success' => $preferences[NotificationCategory::DeploymentSuccess->value],
            /** @var bool */
            'deployment_failure' => $preferences[NotificationCategory::DeploymentFailure->value],
            /** @var bool */
            'project_issue' => $preferences[NotificationCategory::ProjectIssue->value],
            /** @var bool */
            'account_security' => $preferences[NotificationCategory::AccountSecurity->value],
            /**
             * Invitations to an account's verified address, and changes to its team memberships. Invitation emails are
             * sent regardless, since they are the invitation itself.
             */
            'workspace' => $preferences[NotificationCategory::Workspace->value],
            /**
             * Email, independent of the in-app switch above. Only categories that can be emailed are listed:
             * `account_security` and `workspace` have no email preference.
             */
            'email' => [
                /** Default false: a success is only worth a glance at the bell. */
                'deployment_success' => $email[NotificationCategory::DeploymentSuccess->value],
                /** Default true. */
                'deployment_failure' => $email[NotificationCategory::DeploymentFailure->value],
                /** Default true. A crash of the running app, or a push that auto-deploy skipped. */
                'project_issue' => $email[NotificationCategory::ProjectIssue->value],
            ],
        ];
    }
}
