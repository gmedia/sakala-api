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
        'ghs_',
        'github_pat_',
    ];

    /**
     * @param  list<string>  $knownValues  Exact values to remove first, typically
     *                                     from {@see ProjectSecretValues}.
     */
    public function redactString(string $value, array $knownValues = []): string
    {
        if ($knownValues !== []) {
            // strtr prefers the longest match, so a value that contains another
            // one is replaced whole.
            $value = strtr($value, array_fill_keys($knownValues, self::REDACTED));
        }

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
            '~(\\bhttps?://)[^/\\s@]+:[^/\\s@]*@~i',
            '$1'.self::REDACTED.'@',
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
     * @param  array<array-key, mixed>|null  $values
     * @param  list<string>  $knownValues
     * @return array<array-key, mixed>|null
     */
    public function redactArray(?array $values, array $knownValues = []): ?array
    {
        if ($values === null) {
            return null;
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = $this->isSensitiveKey($key)
                ? self::REDACTED
                : match (true) {
                    is_string($value) => $this->redactString($value, $knownValues),
                    is_array($value) => $this->redactArray($value, $knownValues),
                    default => $value,
                };
        }

        return $redacted;
    }

    private function isSensitiveKey(int|string $key): bool
    {
        $normalized = strtolower((string) $key);
        $normalized = preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $key) ?? $normalized;
        $normalized = str_replace(['-', ' '], '_', strtolower($normalized));

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return preg_match(
            '/(?:^|_)(?:token|password|secret|api_key|authorization|access_token|refresh_token|client_secret|database_url)$/',
            $normalized,
        ) === 1;
    }
}
