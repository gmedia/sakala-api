<?php

declare(strict_types=1);

namespace App\Support\GitHub;

use App\Data\Project\ParsedRepositoryData;

final class RepositoryParser
{
    public function parse(string $url): ParsedRepositoryData
    {
        // Handle repository_provider
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            throw new \InvalidArgumentException("Invalid URL: $url");
        }

        // Handle repository_full_name
        $host = strtolower(str_replace('www.', '', $host));
        $repository_provider = explode('.', $host)[0];

        if ($host !== 'github.com') {
            throw new \InvalidArgumentException('Repository provider must be GitHub.');
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            throw new \InvalidArgumentException("Invalid URL: $url");
        }

        $repository_full_name = trim($path, '/');
        $repository_full_name = preg_replace('/\.git$/', '', $repository_full_name);

        if (substr_count($repository_full_name, '/') !== 1) {
            throw new \InvalidArgumentException("Invalid URL: $url");
        }

        return new ParsedRepositoryData(
            repository_provider: $repository_provider,
            repository_full_name: $repository_full_name
        );
    }
}
