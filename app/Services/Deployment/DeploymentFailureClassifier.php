<?php

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Data\Deployment\DeploymentFailureData;
use App\Enums\DeploymentFailureCategory as Category;

/**
 * Turns an agent or control-plane error code into text a student can act on.
 *
 * `userFixable` separates what the student can repair (repository, code,
 * GitHub access) from platform faults. Platform hints say so and only ever ask
 * for a redeploy: there is no setting the student could change for those.
 * Students cannot edit a start command, timeouts, or memory limits, so hints
 * never point at them.
 *
 * Every string here is shown as plain text: the console does not render
 * markdown, so summaries and hints never use backticks or other markup.
 */
final class DeploymentFailureClassifier
{
    private const PLATFORM_FAULT = 'Ini gangguan di sisi Sakala, bukan kesalahan di aplikasimu.';

    private const RETRY = 'Coba deploy ulang. Kalau gagal lagi, hubungi admin Sakala.';

    /**
     * Agent code families for a node that cannot reach a container registry
     * or the network during a deployment. Only codes without an exact match
     * below fall into this family, so a specific code keeps its own mapping.
     */
    private const PLATFORM_NETWORK_PREFIXES = ['runtime_registry', 'runtime_network'];

    public function classify(string $errorCode): DeploymentFailureData
    {
        $failure = $this->classifyKnown($errorCode);

        if ($failure->category === Category::Unknown && $this->isPlatformNetworkCode($errorCode)) {
            return $this->platform(
                $errorCode,
                Category::Node,
                'Server Sakala gagal terhubung ke jaringan atau registry image saat menyiapkan deployment.',
                self::RETRY,
            );
        }

        return $failure;
    }

    private function isPlatformNetworkCode(string $errorCode): bool
    {
        foreach (self::PLATFORM_NETWORK_PREFIXES as $prefix) {
            if (str_starts_with($errorCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function classifyKnown(string $errorCode): DeploymentFailureData
    {
        return match ($errorCode) {
            'repository_not_found' => $this->userFixable(
                $errorCode,
                Category::Checkout,
                'Repository tidak ditemukan di GitHub.',
                'Pastikan repository masih ada dan namanya tidak berubah. Kalau repository-nya private, hubungkan lewat GitHub App, lalu deploy ulang.',
            ),

            'repository_access_denied' => $this->userFixable(
                $errorCode,
                Category::Checkout,
                'Sakala tidak punya izin untuk membaca repository ini.',
                'Buka pengaturan GitHub App Sakala dan beri akses ke repository ini, lalu deploy ulang.',
            ),

            'repository_auth_failed' => $this->userFixable(
                $errorCode,
                Category::Checkout,
                'GitHub menolak akses ke repository ini.',
                'Kalau repository-nya private, hubungkan lewat GitHub App. Kalau sudah terhubung, pasang ulang GitHub App Sakala, lalu deploy ulang.',
            ),

            'repository_credential_unavailable' => $this->userFixable(
                $errorCode,
                Category::Checkout,
                'Sakala tidak mendapat akses sementara ke repository dari GitHub.',
                'Pastikan GitHub App Sakala masih terpasang dan punya akses ke repository ini, lalu deploy ulang.',
            ),

            'repository_access_revoked' => $this->userFixable(
                $errorCode,
                Category::Checkout,
                'Pemilik proyek tidak lagi punya akses ke repository ini di GitHub.',
                'Minta pemilik repository memulihkan akses kamu, lalu deploy ulang. Kalau aksesnya sudah benar, masuk ulang dengan GitHub supaya Sakala bisa memeriksanya lagi.',
            ),

            'repository_commit_not_found' => $this->userFixable(
                $errorCode,
                Category::Checkout,
                'Commit yang mau di-deploy sudah tidak ada di repository, biasanya karena force push.',
                'Deploy ulang supaya Sakala mengambil commit terbaru dari branch proyek.',
            ),

            'repository_credential_expired' => $this->platform(
                $errorCode,
                Category::Checkout,
                'Akses sementara Sakala ke repository habis masa berlakunya sebelum source code selesai diambil.',
                'Coba deploy ulang, Sakala akan meminta akses baru ke GitHub.',
            ),

            'repository_access_check_failed' => $this->platform(
                $errorCode,
                Category::Checkout,
                'Sakala tidak bisa memeriksa akses repository ke GitHub saat ini.',
                self::RETRY,
            ),

            'repository_checkout_failed',
            'runtime_repository_failed' => $this->platform(
                $errorCode,
                Category::Checkout,
                'Sakala gagal mengambil source code dari GitHub.',
                self::RETRY,
            ),

            'runtime_build_failed' => $this->userFixable(
                $errorCode,
                Category::Build,
                'Deployment gagal saat proses build aplikasi.',
                'Buka log deployment untuk melihat error-nya, perbaiki di repository (dependency, script build, atau Dockerfile), lalu commit dan deploy ulang.',
            ),

            'runtime_root_directory_invalid' => $this->userFixable(
                $errorCode,
                Category::Build,
                'Direktori root proyek tidak menunjuk folder yang bisa dipakai di repository.',
                'Buka pengaturan proyek, lalu isi direktori root dengan folder yang ada di branch ini, misalnya apps/web, atau kosongkan untuk memakai root repository. Setelah itu deploy ulang.',
            ),

            'runtime_app_crashed' => $this->userFixable(
                $errorCode,
                Category::Start,
                'Aplikasi berhenti sendiri sesaat setelah dijalankan.',
                'Buka log deployment untuk melihat pesan terakhir sebelum aplikasi berhenti. Penyebab yang sering muncul: environment variable belum diisi atau koneksi ke layanan lain gagal. Perbaiki, lalu deploy ulang.',
            ),

            'runtime_port_conflict' => $this->userFixable(
                $errorCode,
                Category::Start,
                'Environment variable PORT di proyek ini berbeda dengan port yang dipakai Sakala untuk aplikasimu.',
                'Hapus environment variable PORT di pengaturan proyek, karena Sakala sudah mengisinya otomatis, atau samakan nilainya dengan port yang tertulis di detail kegagalan. Lalu deploy ulang.',
            ),

            'runtime_execution_failed',
            'runtime_container_failed',
            'runtime_workload_not_found',
            'runtime_workload_not_running',
            'runtime_reporting_failed',
            'runtime_filesystem_failed' => $this->platform(
                $errorCode,
                Category::Start,
                'Sakala gagal menjalankan container aplikasi.',
                self::RETRY,
            ),

            'runtime_app_not_listening',
            'runtime_health_check_failed' => $this->userFixable(
                $errorCode,
                Category::Health,
                'Aplikasi berjalan, tetapi tidak menerima koneksi di port yang ditentukan Sakala.',
                'Pastikan aplikasimu listen di alamat 0.0.0.0 dengan port dari environment variable PORT. Nilai PORT sudah diisi otomatis oleh Sakala, jadi baca nilainya di kode dan jangan menulis port secara tetap. Listen di localhost saja tidak bisa dijangkau. Perbaiki, lalu deploy ulang.',
            ),

            'runtime_app_unhealthy' => $this->userFixable(
                $errorCode,
                Category::Health,
                'Aplikasi merespons pengecekan dari Sakala dengan error server (status 500 ke atas).',
                'Buka log deployment untuk melihat error-nya. Status di bawah 500 sudah dianggap sehat, jadi yang perlu diperbaiki hanya error server di aplikasimu. Perbaiki, lalu deploy ulang.',
            ),

            'runtime_routing_failed' => $this->platform(
                $errorCode,
                Category::Route,
                'Sakala gagal menyiapkan alamat publik untuk aplikasi.',
                self::RETRY,
            ),

            'runtime_timeout' => $this->userFixable(
                $errorCode,
                Category::Timeout,
                'Build atau start aplikasi melewati batas waktu yang ditetapkan Sakala.',
                'Batas waktunya tidak bisa diubah. Buka log deployment untuk melihat tahap yang lambat, lalu kurangi dependency atau langkah build, atau percepat start aplikasi. Setelah itu deploy ulang.',
            ),

            'command_lease_expired' => $this->platform(
                $errorCode,
                Category::Timeout,
                'Server Sakala berhenti merespons sebelum deployment selesai.',
                self::RETRY,
            ),

            'command_expired' => $this->platform(
                $errorCode,
                Category::Scheduling,
                'Belum ada server Sakala yang tersedia untuk menjalankan deployment.',
                'Coba deploy ulang beberapa menit lagi. Kalau gagal lagi, hubungi admin Sakala.',
            ),

            'runtime_app_out_of_memory' => $this->userFixable(
                $errorCode,
                Category::Resource,
                'Aplikasi dihentikan karena memakai memory melebihi batas.',
                'Batas memory proyek tertulis di detail kegagalan. Kurangi pemakaian memory aplikasi, misalnya jumlah worker atau ukuran cache, lalu deploy ulang.',
            ),

            'runtime_capacity_exceeded' => $this->platform(
                $errorCode,
                Category::Resource,
                'Kapasitas server Sakala sedang penuh.',
                'Coba deploy ulang beberapa menit lagi. Kalau gagal lagi, hubungi admin Sakala.',
            ),

            'runtime_disk_pressure' => $this->platform(
                $errorCode,
                Category::Resource,
                'Ruang disk server Sakala sedang hampir penuh.',
                'Coba deploy ulang beberapa menit lagi. Kalau gagal lagi, hubungi admin Sakala.',
            ),

            'runtime_preflight_failed',
            'runtime_dependency_failed',
            'invalid_runtime_configuration',
            'invalid_runtime_command',
            'unsupported_runtime_command' => $this->platform(
                $errorCode,
                Category::Node,
                'Server Sakala belum siap menjalankan deployment.',
                self::RETRY,
            ),

            'runtime_cancelled' => $this->platform(
                $errorCode,
                Category::Node,
                'Deployment dibatalkan karena server Sakala sedang berhenti atau dialihkan.',
                'Coba deploy ulang. Tidak ada yang perlu diubah di repository.',
            ),

            default => $this->platform(
                $errorCode,
                Category::Unknown,
                'Deployment gagal karena terjadi kesalahan yang belum dikenali.',
                'Coba deploy ulang. Kalau gagal lagi, buka log deployment dan kirim kode kegagalannya ke admin Sakala.',
                faultNote: false,
            ),
        };
    }

    private function userFixable(string $code, Category $category, string $summary, string $hint): DeploymentFailureData
    {
        return new DeploymentFailureData(
            code: $code,
            category: $category,
            summary: $summary,
            recoveryHint: $hint,
            userFixable: true,
        );
    }

    private function platform(
        string $code,
        Category $category,
        string $summary,
        string $hint,
        bool $faultNote = true,
    ): DeploymentFailureData {
        return new DeploymentFailureData(
            code: $code,
            category: $category,
            summary: $summary,
            recoveryHint: $faultNote ? self::PLATFORM_FAULT.' '.$hint : $hint,
            userFixable: false,
        );
    }
}
