<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notification;

use App\Enums\NotificationCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Only what the link changed, so the link does not reveal the rest of the
 * account's preferences.
 *
 * @property NotificationCategory $resource
 */
final class NotificationUnsubscribeResource extends JsonResource
{
    /** @return array{category: string, email: false} */
    public function toArray(Request $request): array
    {
        return [
            /** @var 'deployment_success'|'deployment_failure'|'project_issue' */
            'category' => $this->resource->value,
            /** Always false: email for this category is now off. */
            'email' => false,
        ];
    }
}
