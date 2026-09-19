<?php

declare(strict_types=1);

use App\Actions\Admin\CollectUsageSignalsAction;
use App\Enums\DeploymentStatus;
use App\Enums\UsageSignalType;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\UsageSignalRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('collector uses aligned previous-hour window with no overlap', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Deployment finishes in hour 11 (11:00–12:00).
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-14 10:50:00',
        'finished_at' => '2026-09-14 11:30:00',
    ]);

    // Deployment finishes in hour 12 (12:00–13:00).
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-14 12:10:00',
        'finished_at' => '2026-09-14 12:45:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);

    // Simulate scheduler running at 12:00 → collects [11:00, 12:00).
    // Records get collected_at = 12:00 (the $to boundary).
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00 UTC'));
    $action->handle(
        CarbonImmutable::parse('2026-09-14 12:00:00 UTC')->copy()->subHour()->startOfHour(),
        CarbonImmutable::parse('2026-09-14 12:00:00 UTC')->startOfHour(),
    );

    // Simulate scheduler running at 13:00 → collects [12:00, 13:00).
    // Records get collected_at = 13:00.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 13:00:00 UTC'));
    $action->handle(
        CarbonImmutable::parse('2026-09-14 13:00:00 UTC')->copy()->subHour()->startOfHour(),
        CarbonImmutable::parse('2026-09-14 13:00:00 UTC')->startOfHour(),
    );

    CarbonImmutable::setTestNow();

    // Hour 11 bucket collected at 12:00: exactly 1 successful deployment.
    $hour11Signal = UsageSignalRecord::where('signal_type', UsageSignalType::SuccessfulDeployment->value)
        ->where('collected_at', '=', '2026-09-14 12:00:00')
        ->first();

    expect($hour11Signal)->not->toBeNull();
    expect($hour11Signal->count)->toBe(1);

    // Hour 12 bucket collected at 13:00: exactly 1 successful deployment.
    $hour12Signal = UsageSignalRecord::where('signal_type', UsageSignalType::SuccessfulDeployment->value)
        ->where('collected_at', '=', '2026-09-14 13:00:00')
        ->first();

    expect($hour12Signal)->not->toBeNull();
    expect($hour12Signal->count)->toBe(1);
});
