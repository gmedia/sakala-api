<?php

declare(strict_types=1);

namespace App\Support\User;

use Illuminate\Support\Str;

final class UsernameNormalizer
{
    public function normalize(string $value): string
    {
        $ascii = Str::ascii($value);
        $lower = Str::lower($ascii);

        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($slug, '-');
    }
}
