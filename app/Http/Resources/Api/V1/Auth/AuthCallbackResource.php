<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{user: User, token: string, redirect_url: string} $resource
 */
final class AuthCallbackResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'data' => [
                'token' => $this->resource['token'],
                'redirect_url' => $this->resource['redirect_url'],
            ],
        ];
    }
}
