<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\GithubOAuthFailure;

final class ConsoleAuthenticationRedirect
{
    public function dashboard(): string
    {
        return $this->url('/dashboard');
    }

    public function loginError(GithubOAuthFailure $failure): string
    {
        return $this->url('/login', ['error' => $failure->value]);
    }

    /** @param array<string, string> $query */
    private function url(string $path, array $query = []): string
    {
        $url = rtrim((string) config('sakala.console_url'), '/').$path;

        if ($query === []) {
            return $url;
        }

        return $url.'?'.http_build_query($query, encoding_type: PHP_QUERY_RFC3986);
    }
}
