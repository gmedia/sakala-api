<?php

declare(strict_types=1);

namespace App\Services\Security;

final class SecretRedactionService
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'token',
        'password',
        'secret',
        'app_key',
        'database_url',
        'authorization',
        'api_key',
        'access_token',
        'refresh_token',
        'client_secret',
    ];

    /** @var list<string> */
    private const TOKEN_PREFIXES = [
        'ghp_',
        'gho_',
        'github_pat_',
    ];

    public function redactString(string $value): string
    {
        $keys = implode('|', array_map(
            static fn (string $key): string => preg_quote($key, '/'),
            self::SENSITIVE_KEYS,
        ));

        $value = preg_replace_callback(
            "/((?<![A-Za-z0-9_])[\"']?(?:{$keys})[\"']?\\s*[:=]\\s*)([\"'])(.*?)\\2/is",
            static fn (array $matches): string => $matches[1].$matches[2].self::REDACTED.$matches[2],
            $value,
        ) ?? $value;

        $value = preg_replace_callback(
            "/((?<![A-Za-z0-9_])[\"']?(?:{$keys})[\"']?\\s*[:=]\\s*)(?![\"'])([^\\s,;]+)/i",
            static fn (array $matches): string => $matches[1].self::REDACTED,
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/\\bBearer\\s+[^\\s,;"\']+/i',
            'Bearer '.self::REDACTED,
            $value,
        ) ?? $value;

        $prefixes = implode('|', array_map(
            static fn (string $prefix): string => preg_quote($prefix, '/'),
            self::TOKEN_PREFIXES,
        ));

        return preg_replace(
            "/\\b(?:{$prefixes})[^\\s,;\"']+/i",
            self::REDACTED,
            $value,
        ) ?? $value;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function redactArray(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = match (true) {
                is_string($value) => $this->redactString($value),
                is_array($value) => $this->redactArray($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
