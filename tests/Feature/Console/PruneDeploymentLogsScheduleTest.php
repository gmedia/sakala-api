<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

test('schedules daily log pruning without overlap on one server', function (): void {
    $scheduledPrune = collect(app(Schedule::class)->events())
        ->first(
            fn ($event): bool => str_contains($event->command, 'pilot:prune-logs'),
        );

    expect($scheduledPrune)->not->toBeNull()
        ->and($scheduledPrune->expression)->toBe('0 0 * * *')
        ->and($scheduledPrune->withoutOverlapping)->toBeTrue()
        ->and($scheduledPrune->onOneServer)->toBeTrue();
});
