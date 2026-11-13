<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminOverviewData;
use App\Data\Admin\DeploymentOutcomeCountsData;
use App\Data\Admin\PilotValidationMetricsRequestData;
use App\Data\Admin\TimeRangeData;
use App\Enums\DeploymentStatus;
use App\Enums\OnboardingSource;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform totals and in-range numbers for the admin dashboard. Every number
 * filters on an indexed `created_at` (users, projects, deployments) or counts a
 * whole small table, and the result is cached for a minute, which is what the
 * console's "updated every few minutes" indicator needs.
 */
final class GetAdminOverviewAction
{
    public const CACHE_SECONDS = 60;

    public function __construct(
        private readonly CountFailuresAction $countFailures,
        private readonly GetPilotValidationMetricsAction $pilotMetrics,
    ) {}

    public function handle(TimeRangeData $range): AdminOverviewData
    {
        // The key moves once a minute, so concurrent admins share one result.
        $minute = intdiv(CarbonImmutable::now()->getTimestamp(), self::CACHE_SECONDS);
        $key = 'admin:overview:'.$range->cacheKey().':'.$minute;

        return Cache::remember($key, self::CACHE_SECONDS, fn (): AdminOverviewData => $this->compute($range));
    }

    private function compute(TimeRangeData $range): AdminOverviewData
    {
        $previous = $range->previous();
        /** @var CarbonImmutable $from Overview ranges are always bounded. */
        $from = $range->from;

        return new AdminOverviewData(
            range: $range,
            previousRange: $previous,
            generatedAt: CarbonImmutable::now()->utc(),
            totalUsers: User::query()->count(),
            totalProjects: Project::query()->count(),
            activeProjects: Project::query()->serving()->count(),
            newUsers: $this->createdIn(User::query(), $range)->count(),
            newProjects: $this->createdIn(Project::query()->withTrashed(), $range)->count(),
            deployments: $this->deploymentOutcomes($range),
            previousNewUsers: $previous === null ? 0 : $this->createdIn(User::query(), $previous)->count(),
            previousNewProjects: $previous === null ? 0 : $this->createdIn(Project::query()->withTrashed(), $previous)->count(),
            previousDeployments: $previous === null ? new DeploymentOutcomeCountsData(0, 0, 0, 0) : $this->deploymentOutcomes($previous),
            userGrowth: $this->userGrowth($range),
            failures: $this->countFailures->handle($range),
            newUserSources: $this->newUserSources($range),
            pilot: $this->pilotMetrics->handle(new PilotValidationMetricsRequestData(from: $from, to: $range->to)),
        );
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function createdIn(Builder $query, TimeRangeData $range): Builder
    {
        return $query
            ->when($range->from !== null, fn (Builder $query) => $query->where('created_at', '>=', $range->from))
            ->where('created_at', '<', $range->to);
    }

    private function deploymentOutcomes(TimeRangeData $range): DeploymentOutcomeCountsData
    {
        $counts = $this->createdIn(Deployment::query(), $range)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count);

        $inProgress = 0;
        foreach (DeploymentStatus::activeCases() as $status) {
            $inProgress += $counts->get($status->value, 0);
        }

        return new DeploymentOutcomeCountsData(
            succeeded: $counts->get(DeploymentStatus::Succeeded->value, 0),
            failed: $counts->get(DeploymentStatus::Failed->value, 0),
            cancelled: $counts->get(DeploymentStatus::Cancelled->value, 0),
            inProgress: $inProgress,
        );
    }

    /**
     * New users per WIB day, every day of the range present, zero included.
     *
     * @return list<array{date: string, count: int}>
     */
    private function userGrowth(TimeRangeData $range): array
    {
        $day = $this->wibDayExpression();

        $counts = $this->createdIn(User::query(), $range)
            ->toBase()
            ->selectRaw($day.' as day, COUNT(*) as aggregate')
            ->groupByRaw($day)
            ->pluck('aggregate', 'day');

        return array_map(
            static fn (string $date): array => ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)],
            $range->days(),
        );
    }

    /**
     * New users in the range by the answer to the onboarding question "where
     * did you hear about Sakala". Every source is listed; `null` counts users
     * who have not answered yet.
     *
     * @return list<array{source: string|null, count: int}>
     */
    private function newUserSources(TimeRangeData $range): array
    {
        $counts = $this->createdIn(User::query(), $range)
            ->toBase()
            ->selectRaw('onboarding_source, COUNT(*) as aggregate')
            ->groupBy('onboarding_source')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) ($row->onboarding_source ?? '') => (int) $row->aggregate]);

        $sources = array_map(
            static fn (OnboardingSource $source): array => ['source' => $source->value, 'count' => (int) ($counts[$source->value] ?? 0)],
            OnboardingSource::cases(),
        );
        usort($sources, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $sources[] = ['source' => null, 'count' => (int) ($counts[''] ?? 0)];

        return $sources;
    }

    /**
     * `created_at` (stored in UTC) shifted by TimeRangeData::UTC_OFFSET_HOURS
     * to WIB and cut to a `Y-m-d` day.
     *
     * @return literal-string
     */
    private function wibDayExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(created_at + interval '7 hours', 'YYYY-MM-DD')",
            'mysql', 'mariadb' => "DATE_FORMAT(DATE_ADD(created_at, INTERVAL 7 HOUR), '%Y-%m-%d')",
            default => "strftime('%Y-%m-%d', created_at, '+7 hours')",
        };
    }
}
