<?php

declare(strict_types=1);

use App\Console\Commands\AssignAgentCommandsCommand;
use App\Console\Commands\CollectUsageSignalsCommand;
use App\Console\Commands\ExpireAgentCommandsCommand;
use App\Console\Commands\MarkOfflineAgentNodesCommand;
use App\Console\Commands\PruneDeploymentLogsCommand;
use App\Console\Commands\PruneNotificationsCommand;
use App\Console\Commands\PruneUsageSignalsCommand;
use App\Console\Commands\PruneWorkspaceInvitationsCommand;
use App\Console\Commands\PurgeDeletedProjectsCommand;
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

Schedule::command(MarkOfflineAgentNodesCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(SleepIdleProjectsCommand::class)
    ->everyMinute()
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
