<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A region label, for example `jkt` or `id-jog-1`: lowercase letters and
 * digits in groups joined by single hyphens, at most MAX_LENGTH characters.
 * The same rule applies to what a node reports (`SAKALA_AGENT_REGION`), what
 * an admin sets, and what a project or limit profile asks for, so the values
 * compare as plain strings.
 */
final class RegionSlug implements ValidationRule
{
    public const MAX_LENGTH = 32;

    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $value) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('Region hanya boleh berisi huruf kecil, angka, dan tanda hubung tunggal di antaranya, paling panjang '.self::MAX_LENGTH.' karakter.');
        }
    }
}
