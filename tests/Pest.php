<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

// Multi-session PostgreSQL tests: no RefreshDatabase, schema recreated per
// test, excluded from the default run (see composer test / test:concurrency).
pest()->group('concurrency')->in('Feature/Concurrency');

// Commands become visible at `available_at <= now()`, to the second. A wall clock
// that steps backwards between creating and polling (WSL2 steps about 1.4 seconds
// every 31 seconds) hid them for a moment and failed whichever test was running.
// These suites control the clock instead; `travel()` still moves it.
pest()->beforeEach(function (): void {
    $this->freezeTime();
})->in('Feature/Api', 'Feature/Console', 'Feature/Jobs', 'Unit/Actions', 'Unit/Services', 'Unit/PilotRuntimeLimitServiceTest.php');

// Those tests commit rows on purpose. When a plain `pest` run includes them, the
// RefreshDatabase tests that follow must start from a fresh schema again instead
// of inheriting the committed rows through the shared "already migrated" flag.
pest()->afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
})->in('Feature/Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

require_once __DIR__.'/Support/AgentHeartbeatPayload.php';
require_once __DIR__.'/Support/AgentProtocolFixtures.php';
require_once __DIR__.'/Support/PgsqlConcurrency.php';
/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}
