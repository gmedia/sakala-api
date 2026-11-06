<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Data\Auth\RegisterData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Echoes the request only, so it looks the same for a new and an existing email.
 *
 * @property RegisterData $resource
 */
final class RegistrationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'verification_required' => true,
        ];
    }
}
