<?php

declare(strict_types=1);

namespace App\Support\GitHub;

use App\Data\Project\ParsedRepositoryData;

final class RepositoryParser
{
    public function parse(string $url): ParsedRepositoryData
    {
        // Handle repository_provider
        $parts = parse_url($url);

        // Handle Invalid URL
        if (! is_array($parts)) {
            throw new \InvalidArgumentException('URL repository tidak bisa dibaca. Contoh yang benar: https://github.com/pemilik/nama-repo.');
        }

        // Handle HTTPS
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('URL repository harus memakai HTTPS, misalnya https://github.com/pemilik/nama-repo.');
        }

        // Handle jika ada user atau pass di URL
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URL repository tidak boleh berisi username atau password. Contoh yang benar: https://github.com/pemilik/nama-repo.');
        }

        // Handle jika ada query atau fragment di URL
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('URL repository tidak boleh berisi query atau fragment. Contoh yang benar: https://github.com/pemilik/nama-repo.');
        }

        $host = strtolower(preg_replace('/^www\./', '', $parts['host'] ?? '') ?? '');

        // Handle jika host bukan github
        if ($host !== 'github.com') {
            throw new \InvalidArgumentException('Saat ini Sakala hanya mendukung repository dari GitHub.');
        }

        $repositoryFullName = trim($parts['path'] ?? '', '/');
        $repositoryFullName = preg_replace('/\.git$/', '', $repositoryFullName);

        if ($repositoryFullName === null || substr_count($repositoryFullName, '/') !== 1 || in_array('', explode('/', $repositoryFullName), true)) {
            throw new \InvalidArgumentException('URL repository GitHub harus berbentuk https://github.com/pemilik/nama-repo.');
        }

        return new ParsedRepositoryData(
            repository_provider: 'github',
            repository_full_name: $repositoryFullName,
            repository_url: sprintf('https://github.com/%s', $repositoryFullName)
        );
    }
}
