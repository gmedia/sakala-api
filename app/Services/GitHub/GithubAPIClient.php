<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use App\Exceptions\GitHub\GithubRateLimitedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class GithubAPIClient
{
    private const BASE_URL = 'https://api.github.com';

    private function request(?string $accessToken = null): PendingRequest
    {
        $request = Http::acceptJson();

        if ($accessToken !== null) {
            $request = $request->withToken($accessToken);
        }

        return $request;
    }

    /**
     * Send a GET request to GitHub REST API.
     *
     * Authentication is optional because public GitHub resources
     * can be accessed without an OAuth token.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     *
     * @throws GithubRateLimitedException
     */
    public function get(string $uri, ?string $accessToken = null, array $query = [], array $headers = []): Response
    {
        $response = $this->request($accessToken)
            ->withHeaders($headers)
            ->get(self::BASE_URL.$uri, $query);

        $limited = GithubRateLimitedException::fromResponse($response);

        if ($limited !== null) {
            throw $limited;
        }

        return $response;
    }

    public function hasNextPage(Response $response): bool
    {
        return str_contains(
            $response->header('Link'),
            'rel="next"',
        );
    }
}
