<?php

declare(strict_types=1);

use App\Enums\DeploymentFailureCategory;
use App\Services\Deployment\DeploymentFailureClassifier;

test('classifies build failures', function (string $errorCode) {
    $failure = app(DeploymentFailureClassifier::class)->classify($errorCode);

    expect($failure->code)
        ->toBe($errorCode);

    expect($failure->category)
        ->toBe(DeploymentFailureCategory::Build);

    expect($failure->summary)
        ->not->toBeEmpty();

    expect($failure->recoveryHint)
        ->not->toBeEmpty();
})->with([
    'build_failed',
    'docker_build_failed',
]);

test('classifies runtime execution failures as start failures', function () {
    $failure = app(DeploymentFailureClassifier::class)
        ->classify('runtime_execution_failed');

    expect($failure->category)
        ->toBe(DeploymentFailureCategory::Start);
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
