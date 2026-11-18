<?php

declare(strict_types=1);

use App\Models\ProjectMetricHour;
use App\Models\ProjectMetricMinute;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:32:00', 'UTC'));
});

function hourRowAt(string $bucket, int $replica = 0): ProjectMetricHour
{
    return ProjectMetricHour::query()
        ->where('replica', $replica)
        ->where('bucket', CarbonImmutable::parse($bucket, 'UTC'))
        ->sole();
}

test('minute rows roll up into hourly rows with averages, maxima, and restarts', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());

    metricMinuteRow($deployment, '2026-11-18 09:58:00', ['restart_count' => 1]);
    metricMinuteRow($deployment, '2026-11-18 09:59:00', ['restart_count' => 1]);
    metricMinuteRow($deployment, '2026-11-18 10:00:00', ['restart_count' => 1, 'sample_count' => 2, 'cpu_percent_sum' => 20.0, 'cpu_percent_max' => 15.0, 'memory_bytes_sum' => 2000, 'memory_bytes_max' => 1200]);
    metricMinuteRow($deployment, '2026-11-18 10:01:00', ['restart_count' => 3, 'cpu_percent_sum' => 30.0, 'cpu_percent_max' => 50.0, 'memory_bytes_sum' => 3000, 'memory_bytes_max' => 3000]);
    // The container was recreated: its counter started over.
    metricMinuteRow($deployment, '2026-11-18 10:02:00', ['restart_count' => 1]);
    metricMinuteRow($deployment, '2026-11-18 10:03:00', ['restart_count' => 2, 'memory_limit_bytes' => null]);
    metricMinuteRow($deployment, '2026-11-18 10:03:00', ['replica' => 1, 'restart_count' => 7]);

    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    $previous = hourRowAt('2026-11-18 09:00:00');
    $current = hourRowAt('2026-11-18 10:00:00');

    expect(ProjectMetricHour::query()->count())->toBe(3)
        ->and($previous->sample_count)->toBe(2)
        // The first reading has nothing to compare with.
        ->and($previous->restarts)->toBe(0)
        ->and($current->sample_count)->toBe(5)
        ->and($current->cpu_percent_sum)->toBe(70.0)
        ->and($current->cpu_percent_max)->toBe(50.0)
        ->and($current->memory_bytes_sum)->toBe(7000)
        ->and($current->memory_bytes_max)->toBe(3000)
        ->and($current->memory_limit_bytes)->toBeNull()
        ->and($current->restart_count)->toBe(2)
        // 1 -> 3 (+2), 3 -> 1 (reset, +1), 1 -> 2 (+1).
        ->and($current->restarts)->toBe(4)
        ->and($current->last_sampled_at->toDateTimeString())->toBe('2026-11-18 10:03:30')
        ->and(hourRowAt('2026-11-18 10:00:00', replica: 1)->restarts)->toBe(0);
});

test('running again recomputes the previous and current hour without double counting', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());
    metricMinuteRow($deployment, '2026-11-18 09:10:00');
    metricMinuteRow($deployment, '2026-11-18 10:10:00');

    $this->artisan('projects:roll-up-metrics')->assertSuccessful();
    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    expect(hourRowAt('2026-11-18 09:00:00')->sample_count)->toBe(1);

    // A late sample of the previous hour still reaches it.
    metricMinuteRow($deployment, '2026-11-18 09:59:00', ['cpu_percent_max' => 90.0]);
    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    expect(hourRowAt('2026-11-18 09:00:00')->sample_count)->toBe(2)
        ->and(hourRowAt('2026-11-18 09:00:00')->cpu_percent_max)->toBe(90.0)
        ->and(ProjectMetricHour::query()->count())->toBe(2);
});

test('hours before the previous one are left alone once rolled up', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());
    metricHourRow($deployment, '2026-11-18 08:00:00', ['sample_count' => 99]);
    metricHourRow($deployment, '2026-11-18 10:00:00');
    metricMinuteRow($deployment, '2026-11-18 08:30:00');

    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    expect(hourRowAt('2026-11-18 08:00:00')->sample_count)->toBe(99);
});

test('after the scheduler was down it catches up from the latest hourly row', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());
    metricHourRow($deployment, '2026-11-18 05:00:00', ['sample_count' => 99]);
    metricMinuteRow($deployment, '2026-11-18 05:59:00');
    metricMinuteRow($deployment, '2026-11-18 07:15:00');

    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    expect(hourRowAt('2026-11-18 05:00:00')->sample_count)->toBe(1)
        ->and(hourRowAt('2026-11-18 07:00:00')->sample_count)->toBe(1);
});

test('an hour whose minute rows are partly pruned is never recomputed', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());
    // 24 hours of minute rows reach back to 2026-11-17 10:32, so 10:00 that
    // day is only partly kept.
    metricHourRow($deployment, '2026-11-17 10:00:00', ['sample_count' => 60]);
    metricMinuteRow($deployment, '2026-11-17 10:45:00');

    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    expect(hourRowAt('2026-11-17 10:00:00')->sample_count)->toBe(60)
        ->and(ProjectMetricHour::query()->count())->toBe(1);
});

test('rows past their retention are pruned', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());
    metricMinuteRow($deployment, now()->subHours(24)->subMinute()->startOfMinute());
    $keptMinute = metricMinuteRow($deployment, now()->subHours(23)->startOfMinute());
    metricHourRow($deployment, now()->subDays(30)->subHour()->startOfHour());
    $keptHour = metricHourRow($deployment, now()->subDays(29)->startOfHour());

    $this->artisan('projects:roll-up-metrics')
        ->expectsOutputToContain('pruned 1 minute row(s) older than 24 hours and 1 hourly row(s) older than 30 days')
        ->assertSuccessful();

    expect(ProjectMetricMinute::query()->pluck('id')->all())->toBe([$keptMinute->id])
        ->and(ProjectMetricHour::query()->whereKey($keptHour->id)->exists())->toBeTrue()
        ->and(ProjectMetricHour::query()->where('bucket', '<', now()->subDays(30))->exists())->toBeFalse();
});

test('retention follows the configuration', function (): void {
    config()->set('sakala.metrics.minute_retention_hours', 3);
    config()->set('sakala.metrics.hourly_retention_days', 7);
    [, $deployment] = idleSleepProject(idleSleepNode());
    metricMinuteRow($deployment, now()->subHours(4)->startOfMinute());
    metricMinuteRow($deployment, now()->subHours(2)->startOfMinute());
    metricHourRow($deployment, now()->subDays(8)->startOfHour());

    $this->artisan('projects:roll-up-metrics')->assertSuccessful();

    expect(ProjectMetricMinute::query()->count())->toBe(1)
        ->and(ProjectMetricHour::query()->where('bucket', '<', now()->subDays(7))->exists())->toBeFalse();
});

test('purging a deployment removes its metric rows', function (): void {
    [, $deployment] = idleSleepProject(idleSleepNode());
    metricMinuteRow($deployment, '2026-11-18 10:10:00');
    metricHourRow($deployment, '2026-11-18 10:00:00');

    $deployment->delete();

    expect(ProjectMetricMinute::query()->count())->toBe(0)
        ->and(ProjectMetricHour::query()->count())->toBe(0);
});

test('the roll-up runs every five minutes without overlap on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'projects:roll-up-metrics'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
