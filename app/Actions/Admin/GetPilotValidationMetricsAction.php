<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\PilotValidationMetricsData;
use App\Data\Admin\PilotValidationMetricsRequestData;
use App\Enums\DeploymentFailureCategory;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Deployment\DeploymentFailureClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GetPilotValidationMetricsAction
{
    public function __construct(
        private DeploymentFailureClassifier $failureClassifier
    ) {}

    /**
     * @return array<string, int>
     */
    private function getFailureCategory(
        CarbonImmutable $from,
        CarbonImmutable $to
    ): array {
        $categories = [];

        foreach (DeploymentFailureCategory::cases() as $category) {
            $categories[$category->value] = 0;
        }

        $failures = DB::table('deployments')
            ->where('status', DeploymentStatus::Failed->value)
            ->whereNotNull('failure_code')
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('COUNT(*) as total, failure_code')
            ->groupBy('failure_code')
            ->get();

        foreach ($failures as $failure) {
            $category = $this->failureClassifier
                ->classify((string) $failure->failure_code)
                ->category;

            $categories[$category->value] += (int) $failure->total;
        }

        return $categories;
    }

    public function handle(PilotValidationMetricsRequestData $data): PilotValidationMetricsData
    {
        $now = now()->toImmutable();

        $from = $data->from ?? $now->startOfMonth();
        $to = $data->to ?? $now;

        return new PilotValidationMetricsData(
            from: $from,
            to: $to,

            activatedUsers: User::query()
                ->whereNotNull('onboarding_completed_at')
                ->where('onboarding_completed_at', '>=', $from)
                ->where('onboarding_completed_at', '<', $to)
                ->count(),

            successfulDeployments: Deployment::query()
                ->where('status', DeploymentStatus::Succeeded)
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->count(),

            uniqueDeployers: Deployment::query()
                ->whereNotNull('requested_by')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->distinct()
                ->count('requested_by'),

            repeatDeployers: Deployment::query()
                ->whereNotNull('requested_by')
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->groupBy('requested_by')
                ->havingRaw('COUNT(*) >= 2')
                ->select('requested_by')
                ->getQuery()
                ->getCountForPagination(),

            failureCategories: $this->getFailureCategory($from, $to),

            pilotFeedbackCount: Feedback::query()
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $to)
                ->count(),
        );
    }
}
