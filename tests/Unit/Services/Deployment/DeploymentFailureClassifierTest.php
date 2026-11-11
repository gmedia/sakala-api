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
    ['runtime_app_exec_denied', DeploymentFailureCategory::Start],
    ['runtime_app_out_of_memory', DeploymentFailureCategory::Resource],
    ['runtime_port_conflict', DeploymentFailureCategory::Start],
]);

test('the not listening hint tells the student to listen on 0.0.0.0 with PORT and that Sakala sets PORT', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)->classify($errorCode);

    expect($failure->userFixable)->toBeTrue()
        ->and($failure->recoveryHint)->toContain('listen di alamat 0.0.0.0 dengan port dari environment variable PORT')
        ->and($failure->recoveryHint)->toContain('PORT sudah diisi otomatis oleh Sakala');
})->with(['runtime_app_not_listening', 'runtime_health_check_failed']);

/** @return list<string> Every error code with its own arm in the classifier. */
function classifierErrorCodes(): array
{
    $source = (string) file_get_contents(app_path('Services/Deployment/DeploymentFailureClassifier.php'));
    preg_match_all("/^\s*'([a-z_]+)'(?:,$| =>)/m", $source, $matches);

    return array_values(array_unique($matches[1]));
}

test('the error code list is read from the classifier source', function () {
    expect(classifierErrorCodes())->toContain('runtime_port_conflict', 'runtime_app_not_listening', 'command_expired')
        ->and(count(classifierErrorCodes()))->toBeGreaterThan(30);
});

test('summaries and hints are plain text because the console does not render markdown', function () {
    $classifier = app(DeploymentFailureClassifier::class);

    foreach ([...classifierErrorCodes(), 'something_we_do_not_know', 'runtime_registry_unreachable'] as $code) {
        $failure = $classifier->classify($code);

        foreach ([$failure->summary, $failure->recoveryHint] as $text) {
            expect($text)->not->toMatch('/[`*]|\]\(/', $code);
        }
    }
});

test('unknown registry and network codes read as a platform network fault', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)->classify($errorCode);

    expect($failure->code)->toBe($errorCode)
        ->and($failure->category)->toBe(DeploymentFailureCategory::Node)
        ->and($failure->userFixable)->toBeFalse()
        ->and($failure->summary)->toContain('registry image')
        ->and($failure->recoveryHint)->toContain('sisi Sakala')
        ->and(mb_strtolower($failure->recoveryHint))->toContain('deploy ulang');
})->with(['runtime_registry_unreachable', 'runtime_registry_pull_failed', 'runtime_network_unavailable']);

test('only the registry and network prefixes leave the unknown category', function (string $errorCode) {
    expect(app(DeploymentFailureClassifier::class)->classify($errorCode)->category)
        ->toBe(DeploymentFailureCategory::Unknown);
})->with(['registry_unreachable', 'runtime_unexpected', 'build_runtime_network_failed']);

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
    'runtime_app_exec_denied',
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
        ->and($failure->recoveryHint)->toContain('pengaturan proyek');
});
