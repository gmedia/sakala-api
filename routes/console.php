<?php

declare(strict_types=1);

use App\Console\Commands\AssignAgentCommandsCommand;
use App\Console\Commands\CheckBucketUsageCommand;
use App\Console\Commands\CheckEdgeHealthCommand;
use App\Console\Commands\CollectUsageSignalsCommand;
use App\Console\Commands\DispatchSchedulesCommand;
use App\Console\Commands\EnforceDatabaseStorageQuotaCommand;
use App\Console\Commands\ExpireAgentCommandsCommand;
use App\Console\Commands\MarkOfflineAgentNodesCommand;
use App\Console\Commands\PruneDeploymentLogsCommand;
use App\Console\Commands\PruneNotificationsCommand;
use App\Console\Commands\PruneUsageSignalsCommand;
use App\Console\Commands\PruneWorkspaceInvitationsCommand;
use App\Console\Commands\PurgeDeletedProjectsCommand;
use App\Console\Commands\RollUpProjectMetricsCommand;
use App\Console\Commands\SettleInspectionHoldsCommand;
use App\Console\Commands\SleepIdleProjectsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(AssignAgentCommandsCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(ExpireAgentCommandsCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Fails held deployments at their deadline, and settles any whose
// SettleInspectionHoldsJob was lost.
Schedule::command(SettleInspectionHoldsCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Project schedules (ADR-022): records each due slot and starts its run.
// Does nothing while SAKALA_SCHEDULES_ENABLED is off.
Schedule::command(DispatchSchedulesCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(MarkOfflineAgentNodesCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(SleepIdleProjectsCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CheckEdgeHealthCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Database nodes report sizes with every heartbeat, so a minute keeps a
// workspace at most about a minute past its storage quota before the lock.
Schedule::command(EnforceDatabaseStorageQuotaCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Storage nodes report bucket usage at most once a minute, so a minute
// warns about a bucket near its quota soon after the node sees it.
Schedule::command(CheckBucketUsageCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Recomputes the previous and current hour, so every five minutes keeps the
// 7d and 30d ranges at most five minutes behind.
Schedule::command(RollUpProjectMetricsCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(CollectUsageSignalsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PruneUsageSignalsCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PruneDeploymentLogsCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PruneNotificationsCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PurgeDeletedProjectsCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PruneWorkspaceInvitationsCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
