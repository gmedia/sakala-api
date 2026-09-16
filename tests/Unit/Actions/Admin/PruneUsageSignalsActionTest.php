<?php

declare(strict_types=1);

use App\Actions\Admin\PruneUsageSignalsAction;
use App\Models\UsageSignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00 UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('prune action returns dry run count without deleting', function (): void {
    $oldRecord = UsageSignalRecord::factory()->create([
        'collected_at' => now()->subDays(40),
    ]);

    $newRecord = UsageSignalRecord::factory()->create([
        'collected_at' => now()->subDays(5),
    ]);

    $action = app(PruneUsageSignalsAction::class);
    $result = $action->handle(retentionDays: 30, dryRun: true);

    expect($result['pruned'])->toBe(1);
    expect($result['retention_days'])->toBe(30);

    expect(UsageSignalRecord::whereKey($oldRecord->id))->exists();
    expect(UsageSignalRecord::whereKey($newRecord->id))->exists();
});

test('prune action deletes old records beyond retention', function (): void {
    $oldRecord = UsageSignalRecord::factory()->create([
        'collected_at' => now()->subDays(40),
    ]);

    $newRecord = UsageSignalRecord::factory()->create([
        'collected_at' => now()->subDays(5),
    ]);

    $action = app(PruneUsageSignalsAction::class);
    $result = $action->handle(retentionDays: 30, dryRun: false);

    expect($result['pruned'])->toBeGreaterThanOrEqual(1);
    expect(UsageSignalRecord::whereKey($newRecord->id))->exists();
});

test('prune action respects batch size', function (): void {
    for ($i = 0; $i < 5; $i++) {
        UsageSignalRecord::factory()->create([
            'collected_at' => now()->subDays(40),
        ]);
    }

    $action = app(PruneUsageSignalsAction::class);
    $result = $action->handle(retentionDays: 30, dryRun: false, batchSize: 2);

    expect($result['pruned'])->toBe(5);
    expect(UsageSignalRecord::count())->toBe(0);
});

test('prune action does not delete records within retention window', function (): void {
    $recentRecord = UsageSignalRecord::factory()->create([
        'collected_at' => now()->subDays(10),
    ]);

    $action = app(PruneUsageSignalsAction::class);
    $result = $action->handle(retentionDays: 30, dryRun: false);

    expect($result['pruned'])->toBe(0);
    expect(UsageSignalRecord::whereKey($recentRecord->id))->exists();
});
