<?php

declare(strict_types=1);

use App\Actions\Admin\CollectUsageSignalsAction;
use App\Enums\AgentCommandStatus;
use App\Enums\DeploymentStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UsageSignalType;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\UsageSignalRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-09-14 12:00:00 UTC'),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('collect action records deployment attempts and successful deployments', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-14 10:00:00',
        'finished_at' => '2026-09-14 10:05:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'created_at' => '2026-09-14 11:00:00',
        'failure_code' => 'runtime_build_failed',
        'finished_at' => '2026-09-14 11:05:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $attemptSignal = UsageSignalRecord::where('signal_type', UsageSignalType::DeploymentAttempt->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($attemptSignal)->not->toBeNull();
    expect($attemptSignal->count)->toBe(2);

    $successSignal = UsageSignalRecord::where('signal_type', UsageSignalType::SuccessfulDeployment->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($successSignal)->not->toBeNull();
    expect($successSignal->count)->toBe(1);
});

test('successful deployment created before window but finished inside is counted', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Created before window, finished inside — must still be counted.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-13 23:50:00',
        'finished_at' => '2026-09-14 01:00:00',
    ]);

    // Created and finished before window — must not be counted.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-13 10:00:00',
        'finished_at' => '2026-09-13 10:05:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $successSignal = UsageSignalRecord::where('signal_type', UsageSignalType::SuccessfulDeployment->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($successSignal)->not->toBeNull();
    expect($successSignal->count)->toBe(1);
});

test('successful deployment with no finished_at is not counted', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-14 10:00:00',
        'finished_at' => null,
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $successSignal = UsageSignalRecord::where('signal_type', UsageSignalType::SuccessfulDeployment->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($successSignal)->toBeNull();
});

test('collect action records active projects count', function (): void {
    $user = User::factory()->create();

    Project::factory()->create([
        'user_id' => $user->id,
        'runtime_status' => RuntimeStatus::Running,
    ]);

    Project::factory()->create([
        'user_id' => $user->id,
        'runtime_status' => RuntimeStatus::Deploying,
    ]);

    Project::factory()->create([
        'user_id' => $user->id,
        'runtime_status' => RuntimeStatus::Stopped,
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $activeSignal = UsageSignalRecord::where('signal_type', UsageSignalType::ActiveProjects->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($activeSignal)->not->toBeNull();
    expect($activeSignal->count)->toBe(2);
});

test('collect action records agent failures', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'status' => AgentCommandStatus::Failed,
        'failed_at' => '2026-09-14 10:00:00',
    ]);

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'status' => AgentCommandStatus::Failed,
        'failed_at' => '2026-09-14 11:00:00',
    ]);

    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => '2026-09-14 09:00:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $failureSignal = UsageSignalRecord::where('signal_type', UsageSignalType::AgentFailure->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($failureSignal)->not->toBeNull();
    expect($failureSignal->count)->toBe(2);
});

test('collect action does not record zero agent failures', function (): void {
    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $failureSignal = UsageSignalRecord::where('signal_type', UsageSignalType::AgentFailure->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($failureSignal)->toBeNull();
});

test('collect action records repeated build failures above threshold using canonical code', function (): void {
    config(['sakala.usage_signals.repeat_failure_threshold' => 2]);

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Two canonical runtime_build_failed inside window → should trigger.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'created_at' => '2026-09-14 10:00:00',
        'finished_at' => '2026-09-14 10:05:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'created_at' => '2026-09-14 11:00:00',
        'finished_at' => '2026-09-14 11:05:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $repeatSignal = UsageSignalRecord::where('signal_type', UsageSignalType::RepeatedBuildFailure->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($repeatSignal)->not->toBeNull();
    expect($repeatSignal->count)->toBe(1);
    expect($repeatSignal->tags['threshold'])->toBe(2);
});

test('health and route failures do not increment repeated_build_failure', function (): void {
    config(['sakala.usage_signals.repeat_failure_threshold' => 2]);

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Health and route failures should be ignored.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_health_check_failed',
        'created_at' => '2026-09-14 10:00:00',
        'finished_at' => '2026-09-14 10:05:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_routing_failed',
        'created_at' => '2026-09-14 11:00:00',
        'finished_at' => '2026-09-14 11:05:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $repeatSignal = UsageSignalRecord::where('signal_type', UsageSignalType::RepeatedBuildFailure->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($repeatSignal)->toBeNull();
});

test('collect action skips repeated build failures below threshold', function (): void {
    config(['sakala.usage_signals.repeat_failure_threshold' => 3]);

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'created_at' => '2026-09-14 10:00:00',
        'finished_at' => '2026-09-14 10:05:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'created_at' => '2026-09-14 11:00:00',
        'finished_at' => '2026-09-14 11:05:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $repeatSignal = UsageSignalRecord::where('signal_type', UsageSignalType::RepeatedBuildFailure->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($repeatSignal)->toBeNull();
});

test('repeated build failure uses terminal time, not created_at', function (): void {
    config(['sakala.usage_signals.repeat_failure_threshold' => 2]);

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Both finished inside the window even though created before it.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'created_at' => '2026-09-13 20:00:00',
        'finished_at' => '2026-09-14 01:00:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'created_at' => '2026-09-13 21:00:00',
        'finished_at' => '2026-09-14 02:00:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $repeatSignal = UsageSignalRecord::where('signal_type', UsageSignalType::RepeatedBuildFailure->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($repeatSignal)->not->toBeNull();
    expect($repeatSignal->count)->toBe(1);
});

test('collect action only counts successful deployments finished within window', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Finished before the window — excluded.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-13 10:00:00',
        'finished_at' => '2026-09-13 10:05:00',
    ]);

    // Finished inside the window — included.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-13 23:00:00',
        'finished_at' => '2026-09-14 10:00:00',
    ]);

    $action = app(CollectUsageSignalsAction::class);
    $from = CarbonImmutable::parse('2026-09-14 00:00:00 UTC');
    $to = CarbonImmutable::parse('2026-09-14 12:00:00 UTC');
    $action->handle($from, $to);

    $successSignal = UsageSignalRecord::where('signal_type', UsageSignalType::SuccessfulDeployment->value)
        ->where('collected_at', '>=', $from)
        ->first();

    expect($successSignal)->not->toBeNull();
    expect($successSignal->count)->toBe(1);
});
