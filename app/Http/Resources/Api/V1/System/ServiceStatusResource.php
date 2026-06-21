<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\System;

use App\Data\System\ServiceStatusData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceStatusData */
final class ServiceStatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'service' => $this->service,
            'status' => $this->status,
            'api_version' => $this->apiVersion,
        ];
    }
}
