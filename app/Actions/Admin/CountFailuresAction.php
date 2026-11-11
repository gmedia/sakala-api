<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\FailureBreakdownData;
use App\Data\Admin\TimeRangeData;
use App\Enums\DeploymentFailureCategory;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentFailureClassifier;

/**
 * Groups failed deployments by failure code in SQL (index `(status,
 * created_at)`), then classifies the few distinct codes in PHP with the same
 * classifier the deployment resource uses, so admin numbers and the `failure`
 * field always agree.
 */
final class CountFailuresAction
{
    public function __construct(private readonly DeploymentFailureClassifier $classifier) {}

    public function handle(TimeRangeData $range): FailureBreakdownData
    {
        $rows = Deployment::query()
            ->toBase()
            ->where('status', DeploymentStatus::Failed->value)
            ->when($range->from !== null, fn ($query) => $query->where('created_at', '>=', $range->from))
            ->where('created_at', '<', $range->to)
            ->selectRaw('failure_code, COUNT(*) as aggregate')
            ->groupBy('failure_code')
            ->get();

        $categories = [];
        foreach (DeploymentFailureCategory::cases() as $category) {
            $categories[$category->value] = 0;
        }

        $codes = [];
        $total = 0;

        foreach ($rows as $row) {
            $code = $row->failure_code === null ? null : (string) $row->failure_code;
            $count = (int) $row->aggregate;
            $category = $code === null ? DeploymentFailureCategory::Unknown : $this->classifier->classify($code)->category;

            $categories[$category->value] += $count;
            $codes[] = ['code' => $code, 'category' => $category->value, 'count' => $count];
            $total += $count;
        }

        // Stable order: count, then enum order for categories and code for codes.
        $order = array_flip(array_keys($categories));
        uksort($categories, fn (string $a, string $b): int => [$categories[$b], $order[$a]] <=> [$categories[$a], $order[$b]]);
        usort($codes, fn (array $a, array $b): int => [$b['count'], (string) $a['code']] <=> [$a['count'], (string) $b['code']]);

        return new FailureBreakdownData($total, $categories, $codes);
    }

    /**
     * Failure codes recorded on failed deployments that classify as the given
     * category. `unknown` also matches deployments without a code, which the
     * caller handles through the second element.
     *
     * @return array{0: list<string>, 1: bool} codes, and whether a null code matches
     */
    public function codesFor(DeploymentFailureCategory $category): array
    {
        $recorded = Deployment::query()
            ->toBase()
            ->where('status', DeploymentStatus::Failed->value)
            ->whereNotNull('failure_code')
            ->distinct()
            ->pluck('failure_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->filter(fn (string $code): bool => $this->classifier->classify($code)->category === $category)
            ->all();

        return [array_values($recorded), $category === DeploymentFailureCategory::Unknown];
    }
}
