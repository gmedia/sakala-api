<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InspectionHoldOutcome;
use App\Services\Deployment\DeploymentInspectionHold;
use Illuminate\Console\Command;

final class SettleInspectionHoldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deployments:settle-inspection-holds
                            {--limit=100 : Maximum held deployments to settle in this run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release or fail deployments that wait for their project inspection, including those past the deadline';

    public function handle(DeploymentInspectionHold $hold): int
    {
        $counts = $hold->settleAll(limit: (int) $this->option('limit'));

        $this->info(sprintf(
            'Released %d, failed %d, still waiting %d held deployment(s).',
            $counts[InspectionHoldOutcome::Released->value],
            $counts[InspectionHoldOutcome::Failed->value],
            $counts[InspectionHoldOutcome::Waiting->value],
        ));

        return self::SUCCESS;
    }
}
