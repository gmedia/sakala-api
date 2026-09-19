<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\InstallationTokenLeaseData;
use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

final class GithubInstallationTokenService
{
    public function __construct(private readonly GithubAppJwtService $jwtService) {}

    public function for(GithubInstallation $installation): string
    {
        $this->assertActive($installation);

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

    /**
     * Mint a token limited to one repository with `contents:read` only. Used
     * to lease repository access to an agent for a single command. Never
     * cached: each lease is independent and the agent holds it in memory
     * only for the duration of the checkout.
     */
    public function forRepository(GithubInstallation $installation, int $repositoryId): InstallationTokenLeaseData
    {
        $this->assertActive($installation);

        $response = Http::withToken($this->jwtService->token())
            ->acceptJson()
            ->post("https://api.github.com/app/installations/{$installation->github_installation_id}/access_tokens", [
                'repository_ids' => [$repositoryId],
                'permissions' => ['contents' => 'read'],
            ])
            ->throw()
            ->json();

        $token = $response['token'] ?? null;
        $expiresAt = $response['expires_at'] ?? null;
        if (! is_string($token) || $token === '' || ! is_string($expiresAt)) {
            throw new \RuntimeException('GitHub installation token response is invalid.');
        }

        return new InstallationTokenLeaseData(
            token: $token,
            expiresAt: CarbonImmutable::parse($expiresAt),
        );
    }

    public function forget(GithubInstallation $installation): void
    {
        Cache::forget($this->cacheKey($installation));
    }

    private function assertActive(GithubInstallation $installation): void
    {
        if ($installation->status !== GithubInstallationStatus::Active) {
            throw new HttpResponseException(response()->json([
                'message' => 'GitHub installation is no longer active. Reconnect GitHub and try again.',
            ], 409));
        }
    }

    private function cacheKey(GithubInstallation $installation): string
    {
        return "github-app-installation-token:{$installation->id}";
    }
}
