<?php

declare(strict_types=1);

use App\Actions\Project\PurgeDeletedProjectsAction;
use App\Models\Project;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

test('a purge cannot slip past an agent path that holds the project row', function (): void {
    $project = Project::factory()->create(['deleted_at' => now()->subDays(40)]);

    whileTransactionHoldsLocks(
        holdLocks: function () use ($project): void {
            // What claim, complete, and a late deployment transition do first.
            Project::withTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        },
        contend: function () use ($project): void {
            $result = app(PurgeDeletedProjectsAction::class)->handle();

            // The action counts the lock timeout as a failure and moves on.
            expect($result->purgedCount)->toBe(0)
                ->and($result->failedCount)->toBe(1);

            // Satisfies the helper: the same row is still locked right now.
            Project::withTrashed()->whereKey($project->id)->lockForUpdate()->firstOrFail();
        },
    );

    expect(Project::withTrashed()->find($project->id))->not->toBeNull();
});
