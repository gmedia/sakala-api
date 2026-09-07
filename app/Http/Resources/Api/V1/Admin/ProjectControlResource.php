<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\ProjectControlResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectControlResultData
 */
final class ProjectControlResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->responseContext['project_status']
            ?? $this->project->status;

        $runtimeStatus = $this->responseContext['runtime_status']
            ?? $this->project->runtime_status;

        return [
            'project_id' => $this->project->id,
            'status' => $status,
            'runtime_status' => $runtimeStatus,
            'command' => [
                'id' => $this->command->id,
                'type' => $this->command->type,
                'status' => $this->command->status,
            ],
        ];
    }
}
