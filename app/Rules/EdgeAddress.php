<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Where Sakala Edge reaches a node's runtime router: `host:port`, without a
 * scheme or a path, for example `10.0.47.201:8180`, `[fd00::21]:8180`, or
 * `runtime-01.internal:8180`. The host is an IPv4 address, a bracketed IPv6
 * address, or a DNS name; the port is 1 to 65535. The Edge uses the value
 * verbatim as an upstream.
 */
final class EdgeAddress implements ValidationRule
{
    public const MAX_LENGTH = 255;

    private const MAX_PORT = 65535;

    /** One DNS label: letters, digits, and inner hyphens, 1 to 63 characters. */
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/';

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        $separator = strrpos($value, ':');

        if ($separator === false) {
            return false;
        }

        $host = substr($value, 0, $separator);
        $port = substr($value, $separator + 1);

        if (preg_match('/^[1-9][0-9]{0,4}$/', $port) !== 1 || (int) $port > self::MAX_PORT) {
            return false;
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        // A dotted-quad that is not a valid IPv4 address is a typo, not a name.
        if (preg_match('/^[0-9.]+$/', $host) === 1) {
            return false;
        }

        return preg_match(self::HOSTNAME_PATTERN, $host) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('Alamat Edge harus berbentuk host:port tanpa skema atau path, misalnya 10.0.47.201:8180.');
        }
    }
}
