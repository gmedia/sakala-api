<?php

declare(strict_types=1);

namespace App\Services\Auth;

use BackedEnum;

final class ConsoleAuthenticationRedirect
{
    public function dashboard(): string
    {
        return $this->url('/dashboard');
    }

    public function githubOAuthCallback(): string
    {
        return $this->url('/auth/github/callback');
    }

    public function githubOAuthCallbackError(BackedEnum $failure): string
    {
        return $this->url('/auth/github/callback', ['error' => (string) $failure->value]);
    }

    public function emailVerificationSuccess(): string
    {
        return $this->url('/email-verified', ['status' => 'success']);
    }

    public function emailVerificationError(): string
    {
        return $this->url('/email-verified', ['status' => 'error']);
    }

    public function passwordReset(string $token, string $email): string
    {
        return $this->url('/reset-password', ['token' => $token, 'email' => $email]);
    }

    public function emailChangeSuccess(): string
    {
        return $this->url('/email-changed', ['status' => 'success']);
    }

    public function emailChangeError(): string
    {
        return $this->url('/email-changed', ['status' => 'error']);
    }

    public function githubInstallationSuccess(): string
    {
        return $this->url('/projects/new', ['github_installation' => 'connected']);
    }

    public function githubInstallationError(BackedEnum $failure): string
    {
        return $this->url('/projects/new', [
            'github_installation' => 'error',
            'error' => (string) $failure->value,
        ]);
    }

    public function login(): string
    {
        return $this->url('/login');
    }

    public function loginError(BackedEnum $failure): string
    {
        return $this->url('/login', ['error' => (string) $failure->value]);
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
