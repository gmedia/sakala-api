<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Data\GitHub\GithubPublicResponseData;
use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Exceptions\GitHub\GithubUnavailableException;
use Illuminate\Http\Client\ConnectionException;
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
     * @throws GithubUnavailableException when GitHub cannot be reached
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

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws GithubRateLimitedException
     * @throws GithubUnavailableException when GitHub cannot be reached
     */
    private function send(string $uri, array $query, string $etag): Response
    {
        $headers = $etag === '' ? [] : ['If-None-Match' => $etag];
        $token = $this->token();

        try {
            $response = $this->client->get($uri, $token, $query, $headers);

            // A broken server token must not take public reads down with it.
            if ($token !== null && $this->tokenWasRefused($response)) {
                $this->warnTokenRefused($uri, $response);

                return $this->client->get($uri, null, $query, $headers);
            }

            return $response;
        } catch (ConnectionException) {
            throw new GithubUnavailableException;
        }
    }

    /**
     * GitHub refuses a token with 401 when it is revoked or expired, and with 403
     * when an organization policy blocks it: a fine-grained token living longer
     * than the organization allows, or SAML SSO that the token was not
     * authorized for. The same request without a token can still read a public
     * repository. A rate limit is also a 403 (or 429), but anonymous requests
     * share an even smaller quota, so that one stays a rate limit. The client
     * already throws for those; the check here keeps the rule in one place.
     */
    private function tokenWasRefused(Response $response): bool
    {
        if (! in_array($response->status(), [401, 403], true)) {
            return false;
        }

        return GithubRateLimitedException::fromResponse($response) === null;
    }

    /**
     * Once per repository cache window and message, so a refused token does not
     * write a warning for every public read. GitHub's message says why, for
     * example which organization policy applies. The token itself is never logged.
     */
    private function warnTokenRefused(string $uri, Response $response): void
    {
        $message = mb_substr((string) $response->json('message', ''), 0, 500);
        $key = 'github-public:token-refused:'.sha1($response->status().'|'.$message);

        if (! Cache::add($key, true, self::TTL_REPOSITORY_SECONDS)) {
            return;
        }

        Log::warning('GITHUB_PUBLIC_API_TOKEN was refused by GitHub; this read falls back to an anonymous request.', [
            'status' => $response->status(),
            'github_message' => $message,
            'sso' => $response->header('X-GitHub-SSO') !== '',
            'uri' => $uri,
        ]);
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
