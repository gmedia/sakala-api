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
            throw new \InvalidArgumentException('Invalid URL: Repository URL is not valid.');
        }

        // Handle HTTPS
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new \InvalidArgumentException('Invalid URL: Repository URL must be HTTPS.');
        }

        // Handle jika ada user atau pass di URL
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Invalid URL: Repository URL must not contain user or password.');
        }

        // Handle jika ada query atau fragment di URL
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid URL: Repository URL must not contain query or fragment.');
        }

        $host = strtolower(str_replace('www.', '', $parts['host'] ?? ''));

        // Handle jika host bukan github
        if ($host !== 'github.com') {
            throw new \InvalidArgumentException('Repository provider must be GitHub.');
        }

        $repositoryFullName = trim($parts['path'] ?? '', '/');
        $repositoryFullName = preg_replace('/\.git$/', '', $repositoryFullName);

        if ($repositoryFullName === null || substr_count($repositoryFullName, '/') !== 1) {
            throw new \InvalidArgumentException('Invalid GitHub repository URL');
        }

        return new ParsedRepositoryData(
            repository_provider: 'github',
            repository_full_name: $repositoryFullName,
            repository_url: sprintf('https://github.com/%s', $repositoryFullName)
        );
    }
}
