<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin;

use App\Data\Admin\AdminOverviewData;
use App\Data\Admin\DeploymentOutcomeCountsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AdminOverviewData $resource
 */
final class AdminOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        $failures = $data->failures;

        return [
            'range' => new TimeRangeResource($data->range),
            /** When these numbers were computed. They are cached for up to 60 seconds. */
            'generated_at' => $data->generatedAt->toIso8601String(),
            /** All-time counts, not limited by the range. */
            'totals' => [
                'users' => $data->totalUsers,
                /** Projects that exist now; deleted projects are not counted. */
                'projects' => $data->totalProjects,
                /** Projects with a deployment serving traffic (`active_deployment_id` set). */
                'active_projects' => $data->activeProjects,
            ],
            /** Counts for the range. Deployments count by creation time. */
            'period' => [
                'new_users' => $data->newUsers,
                /** Includes projects created in the range and deleted since. */
                'new_projects' => $data->newProjects,
                'deployments' => $this->outcomes($data->deployments),
            ],
            /** The same counts for the window of equal length just before the range, for trend badges. */
            'previous_period' => [
                'from' => $data->previousRange?->from?->toIso8601String(),
                'to' => $data->previousRange?->to->toIso8601String(),
                'new_users' => $data->previousNewUsers,
                'new_projects' => $data->previousNewProjects,
                'deployments' => $this->outcomes($data->previousDeployments),
            ],
            /**
             * New users per WIB day, every day of the range, oldest first.
             */
            'user_growth' => $data->userGrowth,
            /**
             * Failed deployments in the range by `DeploymentFailureCategory`, every category, most frequent first.
             *
             * @var list<array{category: string, count: int}>
             */
            'failure_categories' => array_map(
                static fn (string $category, int $count): array => ['category' => $category, 'count' => $count],
                array_keys($failures->categories),
                array_values($failures->categories),
            ),
            /**
             * The ten most frequent failure codes in the range. `code` is null for failures recorded without one.
             *
             * @var list<array{code: string|null, category: string, count: int}>
             */
            'top_failure_codes' => array_slice($failures->codes, 0, 10),
            /**
             * New users in the range by their answer to the onboarding source question. Every `OnboardingSource` is
             * listed, most frequent first; the last entry, `source: null`, counts users who have not answered.
             */
            'new_user_sources' => $data->newUserSources,
            /** The pilot validation metrics for the same range, as `GET admin/metrics/pilot-validation` computes them. */
            'pilot' => [
                /** Users who completed onboarding in the range. */
                'activated_users' => $data->pilot->activatedUsers,
                /** Distinct users who requested a deployment in the range. */
                'unique_deployers' => $data->pilot->uniqueDeployers,
                /** Users who requested at least two deployments in the range. */
                'repeat_deployers' => $data->pilot->repeatDeployers,
                'feedback_count' => $data->pilot->pilotFeedbackCount,
            ],
        ];
    }

    /** @return array{succeeded: int, failed: int, cancelled: int, in_progress: int, total: int} */
    private function outcomes(DeploymentOutcomeCountsData $counts): array
    {
        return [
            'succeeded' => $counts->succeeded,
            'failed' => $counts->failed,
            'cancelled' => $counts->cancelled,
            /** Still queued or running. */
            'in_progress' => $counts->inProgress,
            'total' => $counts->total(),
        ];
    }
}
