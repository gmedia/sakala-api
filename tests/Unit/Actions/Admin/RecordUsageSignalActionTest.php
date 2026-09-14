<?php

declare(strict_types=1);

use App\Actions\Admin\RecordUsageSignalAction;
use App\Enums\UsageSignalType;
use App\Models\AuditEvent;
use App\Models\UsageSignalRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('record action inserts a signal record', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $record = $action->handle(
        type: UsageSignalType::DeploymentAttempt,
        count: 5,
        scope: 'global',
    );

    expect($record)->toBeInstanceOf(UsageSignalRecord::class);
    expect($record->signal_type)->toBe(UsageSignalType::DeploymentAttempt);
    expect($record->count)->toBe(5);
    expect($record->scope)->toBe('global');
    expect($record->scope_id)->toBeNull();
    expect($record->tags)->toBeEmpty();
});

test('record action creates audit event for rejected_limits signal', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $action->handle(
        type: UsageSignalType::RejectedLimits,
        count: 1,
        scope: 'user',
        scopeId: (string) Str::uuid(),
        tags: ['limit_name' => 'max_projects'],
    );

    expect(AuditEvent::where('action', 'usage_signal.rejected_limits')->count())->toBe(1);

    $audit = AuditEvent::firstWhere('action', 'usage_signal.rejected_limits');
    expect($audit->metadata['limit_name'])->toBe('max_projects');
});

test('record action creates audit event for manual_intervention signal', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $action->handle(
        type: UsageSignalType::ManualIntervention,
        count: 1,
        scope: 'project',
        tags: ['reason' => 'abuse_detected'],
    );

    expect(AuditEvent::where('action', 'usage_signal.manual_intervention')->count())->toBe(1);
});

test('record action does not create audit event for other signal types', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $initialCount = AuditEvent::count();

    $action->handle(
        type: UsageSignalType::DeploymentAttempt,
        count: 1,
    );

    expect(AuditEvent::count())->toBe($initialCount);
});

test('record action stores custom tags without PII', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $record = $action->handle(
        type: UsageSignalType::RepeatedBuildFailure,
        count: 3,
        scope: 'project',
        scopeId: 'proj-123',
        tags: ['failure_code' => 'build_error', 'threshold' => 3],
    );

    expect($record->tags)->toHaveKey('failure_code');
    expect($record->tags['failure_code'])->toBe('build_error');
    expect($record->tags)->not->toHaveKey('email');
    expect($record->tags)->not->toHaveKey('password');
});

test('record action defaults count to one when omitted', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $record = $action->handle(type: UsageSignalType::ActiveProjects);

    expect($record->count)->toBe(1);
});
