<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GithubPublicResponseData;
use App\Exceptions\GitHub\GithubRateLimitedException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads public GitHub resources on behalf of every user, so it has to be frugal:
 * without a token GitHub allows 60 requests per hour per server IP.
 *
 * Responses are cached for all users. Past the TTL the entry is revalidated with
 * its ETag, and it stays usable for a day as a fallback when GitHub is rate
 * limiting. Only public data may pass through here: see `authenticated()`.
 *
 * The server token (GITHUB_PUBLIC_API_TOKEN) is sent to GitHub and nowhere else.
 * It is deliberately not a GitHub App installation token: that one also reads the
 * private repositories of whoever installed the App, so one user could read
 * another user's repository through a typed URL.
 */
final class GithubPublicReader
{
    public const TTL_REPOSITORY_SECONDS = 300;

    public const TTL_BRANCHES_SECONDS = 120;

    public const TTL_BRANCH_HEAD_SECONDS = 30;

    private const TTL_NOT_FOUND_SECONDS = 30;

    private const RETENTION_SECONDS = 86400;

    public function __construct(
        private readonly GithubAPIClient $client,
    ) {}

    /**
     * A token may also see private repositories, so callers must check that a
     * repository is public before reading anything else about it.
     */
    public function authenticated(): bool
    {
        return $this->token() !== null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  bool  $revalidate  Skip the fresh window, for callers that need the current value.
     *
     * @throws GithubRateLimitedException when limited and nothing is cached
     */
    public function get(string $uri, array $query, int $ttl, bool $revalidate = false): GithubPublicResponseData
    {
        $key = 'github-public:'.sha1($uri.'?'.http_build_query($query));

        /** @var array{status: int, json: mixed, has_next: bool, etag: string, fetched_at: int}|null $cached */
        $cached = Cache::get($key);

        if ($cached !== null && ! $revalidate) {
            $window = $cached['status'] === 404 ? self::TTL_NOT_FOUND_SECONDS : $ttl;

            if (now()->getTimestamp() - $cached['fetched_at'] < $window) {
                return $this->toData($cached);
            }
        }

        $etag = $cached !== null && $cached['status'] === 200 ? $cached['etag'] : '';

        try {
            $response = $this->send($uri, $query, $etag);
        } catch (GithubRateLimitedException $exception) {
            if ($cached !== null && $cached['status'] === 200) {
                return $this->toData($cached);
            }

            throw $exception;
        }

        if ($response->status() === 304 && $cached !== null) {
            $this->remember($key, [...$cached, 'fetched_at' => now()->getTimestamp()]);

            return $this->toData($cached);
        }

        $entry = [
            'status' => $response->status(),
            'json' => $response->json(),
            'has_next' => $this->client->hasNextPage($response),
            'etag' => $response->header('ETag'),
            'fetched_at' => now()->getTimestamp(),
        ];

        if (in_array($response->status(), [200, 404], true)) {
            $this->remember($key, $entry);
        }

        return $this->toData($entry);
    }

    /** @param array<string, mixed> $query */
    private function send(string $uri, array $query, string $etag): Response
    {
        $headers = $etag === '' ? [] : ['If-None-Match' => $etag];
        $token = $this->token();
        $response = $this->client->get($uri, $token, $query, $headers);

        // A revoked server token must not take public reads down with it.
        if ($response->status() === 401 && $token !== null) {
            Log::warning('GITHUB_PUBLIC_API_TOKEN was rejected by GitHub; falling back to anonymous requests.');

            return $this->client->get($uri, null, $query, $headers);
        }

        return $response;
    }

    /** @param array{status: int, json: mixed, has_next: bool, etag: string, fetched_at: int} $entry */
    private function remember(string $key, array $entry): void
    {
        Cache::put($key, $entry, self::RETENTION_SECONDS);
    }

    /** @param array{status: int, json: mixed, has_next: bool, etag: string, fetched_at: int} $entry */
    private function toData(array $entry): GithubPublicResponseData
    {
        return new GithubPublicResponseData($entry['status'], $entry['json'], $entry['has_next']);
    }

    private function token(): ?string
    {
        $token = config('services.github.public_api_token');

        return is_string($token) && $token !== '' ? $token : null;
    }
}
