<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The public S3 endpoint a storage node reports (`metadata.object_storage.endpoint`,
 * ADR-021): `https://host[:port]` without path, query, fragment, or user
 * info, as the agent validates `SAKALA_OBJECT_STORAGE_PUBLIC_ENDPOINT`.
 * `http://` only for a loopback host, for local development. Applications
 * receive it as `AWS_ENDPOINT_URL_S3`, so anything else is refused.
 */
final class ObjectStorageEndpoint implements ValidationRule
{
    public const MAX_LENGTH = 255;

    private const SECURE_SCHEME = 'https';

    private const LOOPBACK_SCHEME = 'http';

    /** Hosts `http://` is accepted for. */
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $scheme = strtolower($parts['scheme']);
        $loopback = in_array($host, self::LOOPBACK_HOSTS, true);

        if ($scheme !== self::SECURE_SCHEME && ! ($scheme === self::LOOPBACK_SCHEME && $loopback)) {
            return false;
        }

        return $loopback || NetworkHost::isValid(trim($host, '[]'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('Endpoint object storage harus berupa https://host[:port] tanpa path, query, atau kredensial.');
        }
    }
}
