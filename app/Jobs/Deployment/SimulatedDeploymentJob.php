<?php

declare(strict_types=1);

namespace App\Jobs\Deployment;

use App\Actions\Deployment\TransitionDeploymentAction;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SimulatedDeploymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        private readonly Deployment $deployment,
    ) {}

    private function shouldSimulateFail(): bool
    {
        return random_int(1, 100) <= 10;
    }

    public function uniqueId(): string
    {
        return (string) $this->deployment->id;
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
        $deployment = $this->deployment->fresh();

        if ($deployment !== null && ! $deployment->status->isTerminal()) {
            app(TransitionDeploymentAction::class)->handle(
                $deployment,
                DeploymentStatus::Failed,
            );
        }
    }

    public function handle(
        TransitionDeploymentAction $transition,
    ): void {
        $deployment = $this->deployment->fresh();

        if ($deployment === null) {
            return;
        }

        $statuses = [
            DeploymentStatus::Queued,
            DeploymentStatus::Cloning,
            DeploymentStatus::Analyzing,
            DeploymentStatus::Building,
            DeploymentStatus::Deploying,
            DeploymentStatus::Routing,
            DeploymentStatus::HealthChecking,
            DeploymentStatus::Succeeded,
        ];

        $currentIndex = array_search($deployment->status, $statuses, true);

        if ($currentIndex === false) {
            return;
        }

        $remainingStatuses = array_slice($statuses, $currentIndex + 1);

        foreach ($remainingStatuses as $status) {
            $deployment = $transition->handle($deployment, $status);

            if ($status !== DeploymentStatus::Succeeded && $this->shouldSimulateFail()) {
                $transition->handle($deployment, DeploymentStatus::Failed);

                return;
            }
        }
    }
}
