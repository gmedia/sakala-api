<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\AgentCommandStatus;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UsageSignalType;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CollectUsageSignalsAction
{
    public function __construct(
        private readonly RecordUsageSignalAction $recordAction,
    ) {}

    /**
     * Collect bounded operational signals from existing tables.
     * This action is intentionally read-heavy and called on a schedule.
     */
    public function handle(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        DB::transaction(function () use ($from, $to): void {
            $this->collectDeploymentSignals($from, $to);
            $this->collectActiveProjectsSignal($from, $to);
            $this->collectAgentFailureSignals($from, $to);
            $this->collectRepeatedBuildFailureSignals($from, $to);
        });
    }

    private function collectDeploymentSignals(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $attempts = Deployment::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        $this->recordAction->handle(
            type: UsageSignalType::DeploymentAttempt,
            count: $attempts,
            scope: 'global',
            collectedAt: $to,
        );

        // Terminal signals use the time the event actually completed
        // (finished_at) rather than when it was created, so deployments
        // started before the window but finished inside it are still
        // counted.
        $successful = Deployment::query()
            ->where('status', DeploymentStatus::Succeeded)
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $to)
            ->count();

        if ($successful > 0) {
            $this->recordAction->handle(
                type: UsageSignalType::SuccessfulDeployment,
                count: $successful,
                scope: 'global',
                collectedAt: $to,
            );
        }
    }

    private function collectActiveProjectsSignal(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $active = Project::query()
            ->where('runtime_status', RuntimeStatus::Running)
            ->orWhere('runtime_status', RuntimeStatus::Deploying)
            ->count();

        $this->recordAction->handle(
            type: UsageSignalType::ActiveProjects,
            count: $active,
            scope: 'global',
            collectedAt: $to,
        );
    }

    private function collectAgentFailureSignals(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $failures = AgentCommand::query()
            ->where('status', AgentCommandStatus::Failed)
            ->where('failed_at', '>=', $from)
            ->where('failed_at', '<', $to)
            ->count();

        if ($failures > 0) {
            $this->recordAction->handle(
                type: UsageSignalType::AgentFailure,
                count: $failures,
                scope: 'global',
                collectedAt: $to,
            );
        }
    }

    private function collectRepeatedBuildFailureSignals(
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): void {
        $threshold = (int) config('sakala.usage_signals.repeat_failure_threshold', 3);

        // Only canonical runtime build failures count toward this signal;
        // other failure categories (checkout, health, route, timeout, etc.)
        // are intentionally excluded.
        $repeaters = Deployment::query()
            ->where('status', DeploymentStatus::Failed)
            ->where('failure_code', 'runtime_build_failed')
            ->whereNotNull('finished_at')
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $to)
            ->groupBy('project_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold])
            ->select('project_id')
            ->getQuery()
            ->getCountForPagination();

        if ($repeaters > 0) {
            $this->recordAction->handle(
                type: UsageSignalType::RepeatedBuildFailure,
                count: $repeaters,
                scope: 'global',
                tags: ['threshold' => $threshold],
                collectedAt: $to,
            );
        }
    }
}
