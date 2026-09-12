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
