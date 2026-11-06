<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Data\Auth\AuthOptionsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AuthOptionsData */
final class AuthOptionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'email_delivery_enabled' => $this->emailDeliveryEnabled,
            'providers' => [
                'github' => $this->githubEnabled,
                'google' => $this->googleEnabled,
            ],
        ];
    }
}
