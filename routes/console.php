<?php

declare(strict_types=1);

use App\Console\Commands\CollectUsageSignalsCommand;
use App\Console\Commands\PruneUsageSignalsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CollectUsageSignalsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command(PruneUsageSignalsCommand::class)
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
