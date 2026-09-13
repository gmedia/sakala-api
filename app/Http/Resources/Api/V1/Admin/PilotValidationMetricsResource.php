<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\PilotValidationMetricsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PilotValidationMetricsData $resource
 */
final class PilotValidationMetricsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource->from->toIso8601String(),
            'to' => $this->resource->to->toIso8601String(),

            'activated_users' => $this->resource->activatedUsers,
            'successful_deployments' => $this->resource->successfulDeployments,
            'unique_deployers' => $this->resource->uniqueDeployers,
            'repeat_deployers' => $this->resource->repeatDeployers,

            'failure_categories' => $this->resource->failureCategories,

            'pilot_feedback_count' => $this->resource->pilotFeedbackCount,
        ];
    }
}
