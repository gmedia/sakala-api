<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Models\GithubInstallation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

final class GithubInstallationTokenService
{
    public function __construct(private readonly GithubAppJwtService $jwtService) {}

    public function for(GithubInstallation $installation): string
    {
        $cacheKey = $this->cacheKey($installation);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return Crypt::decryptString($cached);
        }

        $response = Http::withToken($this->jwtService->token())
            ->acceptJson()
            ->post("https://api.github.com/app/installations/{$installation->github_installation_id}/access_tokens")
            ->throw()
            ->json();

        $token = $response['token'] ?? null;
        $expiresAt = $response['expires_at'] ?? null;
        if (! is_string($token) || ! is_string($expiresAt)) {
            throw new \RuntimeException('GitHub installation token response is invalid.');
        }

        $cacheUntil = CarbonImmutable::parse($expiresAt)->subMinutes(5);
        if ($cacheUntil->isBefore(now()->addMinute())) {
            $cacheUntil = now()->addMinute()->toImmutable();
        }
        Cache::put($cacheKey, Crypt::encryptString($token), $cacheUntil);

        return $token;
    }

    public function forget(GithubInstallation $installation): void
    {
        Cache::forget($this->cacheKey($installation));
    }

    private function cacheKey(GithubInstallation $installation): string
    {
        return "github-app-installation-token:{$installation->id}";
    }
}
