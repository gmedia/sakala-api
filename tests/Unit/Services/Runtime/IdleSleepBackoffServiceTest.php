<?php

declare(strict_types=1);

use App\Services\Runtime\IdleSleepBackoffService;

test('the wait starts at the idle window, doubles per failure, and stops at a day', function (int $window, int $failures, int $expected): void {
    expect((new IdleSleepBackoffService)->delayMinutes($window, $failures))->toBe($expected);
})->with([
    'no failure' => [30, 0, 0],
    'first failure' => [30, 1, 30],
    'second' => [30, 2, 60],
    'third' => [30, 3, 120],
    'sixth' => [30, 6, 960],
    'seventh hits the cap' => [30, 7, 1440],
    'many failures stay capped' => [30, 10_000, 1440],
    'a window longer than a day' => [2880, 1, 1440],
    'a one minute window' => [1, 3, 4],
]);
