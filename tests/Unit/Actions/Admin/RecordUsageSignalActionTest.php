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

test('record action creates audit event for manual_intervention signal with allowed key', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $action->handle(
        type: UsageSignalType::ManualIntervention,
        count: 1,
        scope: 'project',
        tags: ['action_code' => 'manual_suspend'],
    );

    expect(AuditEvent::where('action', 'usage_signal.manual_intervention')->count())->toBe(1);

    $audit = AuditEvent::firstWhere('action', 'usage_signal.manual_intervention');
    expect($audit->metadata['action_code'])->toBe('manual_suspend');
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

test('record action filters disallowed tag keys per signal type allowlist', function (): void {
    $action = app(RecordUsageSignalAction::class);

    // threshold is allowed for RepeatedBuildFailure; free_text is not.
    $record = $action->handle(
        type: UsageSignalType::RepeatedBuildFailure,
        count: 2,
        tags: ['threshold' => 5, 'free_text' => 'should be dropped'],
    );

    expect($record->tags)->toHaveKey('threshold');
    expect($record->tags['threshold'])->toBe(5);
    expect($record->tags)->not->toHaveKey('free_text');
});

test('record action strips globally denied keys even when present in tags', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $record = $action->handle(
        type: UsageSignalType::RejectedLimits,
        count: 1,
        scope: 'user',
        tags: [
            'limit_name' => 'max_projects',
            'email' => 'hacker@example.com',
            'password' => 'secret123',
            'token' => 'ghp_xxxxxxxxxxxx',
            'secret' => 'do_not_store_this',
            'reason' => 'malicious input',
        ],
    );

    expect($record->tags['limit_name'])->toBe('max_projects');
    expect($record->tags)->not->toHaveKey('email');
    expect($record->tags)->not->toHaveKey('password');
    expect($record->tags)->not->toHaveKey('token');
    expect($record->tags)->not->toHaveKey('secret');
    expect($record->tags)->not->toHaveKey('reason');
});

test('audit event metadata is also sanitized against the same allowlist and denylist', function (): void {
    $action = app(RecordUsageSignalAction::class);

    $action->handle(
        type: UsageSignalType::RejectedLimits,
        count: 1,
        scope: 'user',
        scopeId: (string) Str::uuid(),
        tags: [
            'limit_name' => 'max_projects',
            'email' => 'admin@sakala.dev',
            'api_key' => 'sk-xxxxxx',
        ],
    );

    $audit = AuditEvent::firstWhere('action', 'usage_signal.rejected_limits');
    expect($audit->metadata['limit_name'])->toBe('max_projects');
    expect($audit->metadata)->not->toHaveKey('email');
    expect($audit->metadata)->not->toHaveKey('api_key');
});
