<?php

declare(strict_types=1);

namespace App\Support\User;

use Illuminate\Support\Str;

final class UsernameNormalizer
{
    public function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '-')
            ->trim('-')
            ->toString();
    }
}
