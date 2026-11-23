<?php

declare(strict_types=1);

namespace App\Support\Legal;

use App\Data\Legal\LegalChangeData;
use App\Enums\LegalDocument;

/**
 * The summary each announced document version gets in its email. A version is
 * announced only once it is here, so nobody gets an email that says nothing
 * about what changed. Keep each summary in step with the changelog entry on
 * the site that announces the same version.
 */
final class LegalChangeCatalog
{
    public function find(string $version): ?LegalChangeData
    {
        return $this->all()[$version] ?? null;
    }

    /** @return array<string, LegalChangeData> keyed by version */
    public function all(): array
    {
        return [
            'privasi-v2' => new LegalChangeData(
                document: LegalDocument::PrivacyNotice,
                version: 'privasi-v2',
                previousVersion: 'privasi-v1',
                changes: [
                    'Ada bagian baru tentang database PostgreSQL terkelola, yang masih pilot. Isi database adalah datamu dan data pengguna aplikasimu, dan kami memprosesnya hanya untuk menjalankan proyekmu.',
                    'Setiap database terkelola di-backup setiap malam dalam bentuk terenkripsi, dan salinannya disimpan sekitar 14 hari. Menghapus database langsung menghapusnya dari server database, tetapi salinannya masih ada di backup selama itu.',
                    'Database tidak ikut terhapus saat proyek dihapus. Database di workspace pribadimu ikut dihapus saat akunmu dihapus.',
                    'Password database hanya bisa dibuka pemilik workspace dan admin Sakala, dan setiap pembukaannya dicatat.',
                    'Backup data akun dan proyek kini juga disalin, tetap terenkripsi, ke server kedua di pusat data yang sama.',
                ],
            ),
        ];
    }
}
