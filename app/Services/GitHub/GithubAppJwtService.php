<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Firebase\JWT\JWT;

final class GithubAppJwtService
{
    public function token(): string
    {
        $path = config('services.github_app.private_key_path');
        $appId = config('services.github_app.app_id');
        if (! is_string($path) || $path === '' || ! is_readable($path) || ! is_string($appId) || $appId === '') {
            throw new \RuntimeException('GitHub App private key is not configured.');
        }

        $key = file_get_contents($path);
        if ($key === false) {
            throw new \RuntimeException('GitHub App private key cannot be read.');
        }

        return JWT::encode([
            'iat' => now()->subMinute()->getTimestamp(),
            'exp' => now()->addMinutes(9)->getTimestamp(),
            'iss' => $appId,
        ], $key, 'RS256');
    }
}
