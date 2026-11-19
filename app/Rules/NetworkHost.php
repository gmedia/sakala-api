<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A host without a port, scheme, or path: an IPv4 address, an IPv6 address
 * without brackets, or a DNS name. Used for the database endpoint a node
 * reports, which becomes `DB_HOST` and the host of `DATABASE_URL`.
 */
final class NetworkHost implements ValidationRule
{
    /** Longest DNS name. */
    public const MAX_LENGTH = 253;

    /** Labels of letters, digits, and inner hyphens, 1 to 63 characters, joined by dots. */
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/';

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // A dotted-quad that is not a valid IPv4 address is a typo, not a name.
        if (preg_match('/^[0-9.]+$/', $value) === 1) {
            return false;
        }

        return preg_match(self::HOSTNAME_PATTERN, $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('Host database harus berupa alamat IP atau nama DNS tanpa port, skema, atau path.');
        }
    }
}
