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
            'repository_checkout_failed',
            'repository_not_found',
            'repository_access_denied',
            'repository_auth_failed',
            'repository_credential_expired',
            'repository_commit_not_found' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Checkout,
                summary: 'Deployment gagal saat mengambil source code.',
                recoveryHint: 'Periksa repository, branch, commit, dan akses GitHub.',
            ),

            'runtime_build_failed' => new DeploymentFailureData(
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

            'runtime_health_check_failed' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Health,
                summary: 'Deployment gagal saat health check aplikasi.',
                recoveryHint: 'Periksa apakah aplikasi berhasil berjalan dan merespons pada port yang digunakan.',
            ),

            'runtime_routing_failed' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Route,
                summary: 'Deployment gagal saat menyiapkan routing aplikasi.',
                recoveryHint: 'Periksa konfigurasi domain, port, dan routing aplikasi.',
            ),

            'runtime_timeout' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Timeout,
                summary: 'Deployment gagal karena proses melebihi batas waktu.',
                recoveryHint: 'Periksa proses deployment dan konfigurasi timeout aplikasi.',
            ),

            'runtime_capacity_exceeded',
            'runtime_disk_pressure' => new DeploymentFailureData(
                code: $errorCode,
                category: DeploymentFailureCategory::Resource,
                summary: 'Deployment gagal karena resource runtime tidak mencukupi.',
                recoveryHint: 'Periksa penggunaan CPU, memory, dan disk pada runtime.',
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
