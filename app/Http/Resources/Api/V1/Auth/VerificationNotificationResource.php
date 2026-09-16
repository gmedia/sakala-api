<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Data\Auth\VerificationNotificationData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VerificationNotificationData */
final class VerificationNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'request_accepted' => $this->requestAccepted,
        ];
    }
}
