<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Data\Deployment\DeploymentFailureData;
use App\Enums\DeploymentFailureCategory;

final class DeploymentFailureClassifier
{
    public function classify(string $errorCode): DeploymentFailureData
    {
        return match ($errorCode) {
            'build_failed',
            'docker_build_failed' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Build,
                summary: 'Deployment gagal saat proses build aplikasi.',
                recoveryHint: 'Periksa konfigurasi build dan dependency aplikasi.',
            ),

            'runtime_execution_failed' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Start,
                summary: 'Deployment gagal saat menjalankan aplikasi.',
                recoveryHint: 'Periksa konfigurasi runtime dan command untuk menjalankan aplikasi.',
            ),

            default => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Unknown,
                summary: 'Deployment gagal karena terjadi kesalahan yang belum dikenali.',
                recoveryHint: 'Periksa log deployment untuk informasi lebih lanjut.',
            ),
        };
    }
}
