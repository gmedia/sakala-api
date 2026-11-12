<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $resource
 */
final class UnreadNotificationCountResource extends JsonResource
{
    /** @return array{count: int} */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'count' => $this->resource,
        ];
    }
}
