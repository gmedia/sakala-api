<?php

declare(strict_types=1);

namespace App\Services\GitHub;

use Illuminate\Support\Facades\Http;

final class GitHubService
{
    public function getUser(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://api.github.com/user');

        return $response->json();
    }

    public function getUserEmails(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://api.github.com/user/emails');

        return $response->json();
    }
}
