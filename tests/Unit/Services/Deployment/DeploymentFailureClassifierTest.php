<?php

declare(strict_types=1);

use App\Enums\DeploymentFailureCategory;
use App\Services\Deployment\DeploymentFailureClassifier;

test('classifies canonical deployment failure codes', function (
    string $errorCode,
    DeploymentFailureCategory $category,
) {
    $failure = app(DeploymentFailureClassifier::class)
        ->classify($errorCode);

    expect($failure->code)
        ->toBe($errorCode);

    expect($failure->category)
        ->toBe($category);

    expect($failure->summary)
        ->not->toBeEmpty();

    expect($failure->recoveryHint)
        ->not->toBeEmpty();
})->with([
    'checkout' => [
        'repository_checkout_failed',
        DeploymentFailureCategory::Checkout,
    ],
    'build' => [
        'runtime_build_failed',
        DeploymentFailureCategory::Build,
    ],
    'start' => [
        'runtime_execution_failed',
        DeploymentFailureCategory::Start,
    ],
    'health' => [
        'runtime_health_check_failed',
        DeploymentFailureCategory::Health,
    ],
    'route' => [
        'runtime_routing_failed',
        DeploymentFailureCategory::Route,
    ],
    'timeout' => [
        'runtime_timeout',
        DeploymentFailureCategory::Timeout,
    ],
    'resource' => [
        'runtime_capacity_exceeded',
        DeploymentFailureCategory::Resource,
    ],
]);

test('classifies additional checkout failure codes', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)
        ->classify($errorCode);

    expect($failure->code)
        ->toBe($errorCode);

    expect($failure->category)
        ->toBe(DeploymentFailureCategory::Checkout);
})->with([
    'repository_not_found',
    'repository_access_denied',
    'repository_auth_failed',
    'repository_credential_expired',
    'repository_commit_not_found',
]);

test('classifies disk pressure as a resource failure', function () {
    $failure = app(DeploymentFailureClassifier::class)
        ->classify('runtime_disk_pressure');

    expect($failure->category)
        ->toBe(DeploymentFailureCategory::Resource);
});

test('falls back to unknown for unsupported failure codes', function () {
    $failure = app(DeploymentFailureClassifier::class)
        ->classify('something_we_do_not_know');

    expect($failure->category)
        ->toBe(DeploymentFailureCategory::Unknown);

    expect($failure->summary)
        ->toBe('Deployment gagal karena terjadi kesalahan yang belum dikenali.');

    expect($failure->recoveryHint)
        ->not->toContain('retry');
});

test('new runtime failure codes map to stable categories', function (
    string $errorCode,
    DeploymentFailureCategory $category,
) {
    expect(app(DeploymentFailureClassifier::class)->classify($errorCode)->category)
        ->toBe($category);
})->with([
    ['runtime_app_not_listening', DeploymentFailureCategory::Health],
    ['runtime_app_unhealthy', DeploymentFailureCategory::Health],
    ['runtime_app_crashed', DeploymentFailureCategory::Start],
    ['runtime_app_out_of_memory', DeploymentFailureCategory::Resource],
    ['runtime_port_conflict', DeploymentFailureCategory::Start],
]);

test('the not listening hint tells the student to listen on 0.0.0.0:$PORT and that Sakala sets PORT', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)->classify($errorCode);

    expect($failure->userFixable)->toBeTrue()
        ->and($failure->recoveryHint)->toContain('0.0.0.0:$PORT')
        ->and($failure->recoveryHint)->toContain('`PORT` sudah diisi otomatis oleh Sakala');
})->with(['runtime_app_not_listening', 'runtime_health_check_failed']);

test('checkout codes with different remedies get different hints', function () {
    $classifier = app(DeploymentFailureClassifier::class);

    $hints = array_map(
        static fn (string $code): string => $classifier->classify($code)->recoveryHint,
        ['repository_access_denied', 'repository_commit_not_found', 'repository_credential_expired', 'repository_not_found'],
    );

    expect(array_unique($hints))->toHaveCount(4)
        ->and($classifier->classify('repository_access_denied')->recoveryHint)->toContain('GitHub App')
        ->and($classifier->classify('repository_commit_not_found')->recoveryHint)->toContain('commit terbaru')
        ->and($classifier->classify('repository_credential_expired')->recoveryHint)->toContain('deploy ulang');
});

test('user fixable failures never read as a platform fault', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)->classify($errorCode);

    expect($failure->userFixable)->toBeTrue()
        ->and($failure->recoveryHint)->not->toContain('sisi Sakala')
        ->and(mb_strtolower($failure->recoveryHint))->toContain('deploy ulang');
})->with([
    'repository_not_found',
    'repository_access_denied',
    'repository_auth_failed',
    'repository_credential_unavailable',
    'repository_commit_not_found',
    'repository_access_revoked',
    'runtime_build_failed',
    'runtime_app_crashed',
    'runtime_app_not_listening',
    'runtime_app_unhealthy',
    'runtime_app_out_of_memory',
    'runtime_port_conflict',
    'runtime_root_directory_invalid',
    'runtime_health_check_failed',
    'runtime_timeout',
]);

test('platform faults say so, ask for a redeploy, and never ask the student to fix config', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)->classify($errorCode);

    expect($failure->userFixable)->toBeFalse()
        ->and($failure->recoveryHint)->toContain('sisi Sakala')
        ->and(mb_strtolower($failure->recoveryHint))->toContain('deploy ulang')
        ->and(mb_strtolower($failure->recoveryHint))->not->toContain('konfigurasi')
        ->and(mb_strtolower($failure->recoveryHint))->not->toContain('periksa');
})->with([
    'repository_credential_expired',
    'repository_access_check_failed',
    'repository_checkout_failed',
    'runtime_repository_failed',
    'runtime_execution_failed',
    'runtime_container_failed',
    'runtime_workload_not_found',
    'runtime_workload_not_running',
    'runtime_reporting_failed',
    'runtime_filesystem_failed',
    'runtime_routing_failed',
    'command_lease_expired',
    'command_expired',
    'runtime_capacity_exceeded',
    'runtime_disk_pressure',
    'runtime_preflight_failed',
    'runtime_dependency_failed',
    'invalid_runtime_configuration',
    'invalid_runtime_command',
    'unsupported_runtime_command',
    'runtime_cancelled',
]);

test('a cancelled deployment does not read as an unknown failure', function () {
    $failure = app(DeploymentFailureClassifier::class)->classify('runtime_cancelled');

    expect($failure->category)->not->toBe(DeploymentFailureCategory::Unknown)
        ->and($failure->summary)->toContain('dibatalkan')
        ->and($failure->summary)->not->toContain('belum dikenali');
});

test('hints never point at settings the student cannot edit', function (string $errorCode) {
    $hint = mb_strtolower(app(DeploymentFailureClassifier::class)->classify($errorCode)->recoveryHint);

    expect($hint)->not->toContain('start command')
        ->and($hint)->not->toContain('konfigurasi timeout');
})->with([
    'runtime_execution_failed',
    'runtime_container_failed',
    'runtime_timeout',
    'runtime_app_crashed',
    'runtime_health_check_failed',
]);

test('failure category values stay stable for consumers', function () {
    expect(array_map(static fn (DeploymentFailureCategory $case): string => $case->value, DeploymentFailureCategory::cases()))
        ->toBe(['checkout', 'build', 'start', 'health', 'route', 'timeout', 'resource', 'node', 'scheduling', 'unknown']);
});

test('an invalid root directory points at the project setting', function () {
    $failure = app(DeploymentFailureClassifier::class)->classify('runtime_root_directory_invalid');

    expect($failure->category)->toBe(DeploymentFailureCategory::Build)
        ->and($failure->recoveryHint)->toContain('direktori root')
        ->and($failure->recoveryHint)->toContain('pengaturan project');
});
