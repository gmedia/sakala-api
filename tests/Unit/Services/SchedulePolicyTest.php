<?php

declare(strict_types=1);

use App\Services\Schedule\SchedulePolicy;
use Carbon\CarbonImmutable;

test('only standard five-field expressions are accepted', function (string $expression, bool $valid): void {
    expect(SchedulePolicy::isValidExpression($expression))->toBe($valid);
})->with([
    ['30 2 * * *', true],
    ['*/15 * * * *', true],
    ['0 9 * * 1-5', true],
    ['@daily', false],
    ['* * * *', false],
    ['0 0 * * * *', false],
    ['60 * * * *', false],
]);

test('slots are read in the schedule timezone and returned in UTC', function (): void {
    $policy = new SchedulePolicy;
    $at = CarbonImmutable::parse('2026-11-24 10:07:30', 'UTC');

    expect($policy->nextSlotAfter('30 2 * * *', 'Asia/Jakarta', $at)->toIso8601ZuluString())->toBe('2026-11-24T19:30:00Z')
        ->and($policy->latestSlotAtOrBefore('*/15 * * * *', 'Asia/Jakarta', $at)->toIso8601ZuluString())->toBe('2026-11-24T10:00:00Z')
        ->and($policy->latestSlotAtOrBefore('*/15 * * * *', 'Asia/Jakarta', CarbonImmutable::parse('2026-11-24 10:15:00', 'UTC'))->toIso8601ZuluString())->toBe('2026-11-24T10:15:00Z');
});

test('the shortest gap is measured over the next slots, uneven expressions included', function (): void {
    $policy = new SchedulePolicy;

    expect($policy->shortestGapMinutes('*/15 * * * *', 'Asia/Jakarta'))->toBe(15)
        ->and($policy->shortestGapMinutes('0,5 * * * *', 'Asia/Jakarta'))->toBe(5)
        ->and($policy->shortestGapMinutes('0 3 * * *', 'Asia/Jakarta'))->toBe(24 * 60);
});

test('the deadline is the start deadline, or the next slot when that comes sooner', function (): void {
    $policy = new SchedulePolicy;
    $slot = CarbonImmutable::parse('2026-11-24 10:00:00', 'UTC');

    expect($policy->deadlineFor('0 * * * *', 'Asia/Jakarta', $slot)->toIso8601ZuluString())->toBe('2026-11-24T10:10:00Z')
        ->and($policy->deadlineFor('*/5 * * * *', 'Asia/Jakarta', $slot)->toIso8601ZuluString())->toBe('2026-11-24T10:05:00Z');
});
