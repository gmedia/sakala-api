<?php

declare(strict_types=1);

namespace App\Support\User;

use App\Models\User;
use Illuminate\Support\Str;

final class UsernameGenerator
{
    private const MAX_LENGTH = 50;

    public function __construct(
        private UsernameNormalizer $normalizer,
    ) {}

    public function generate(string $value): string
    {
        $base = $this->normalizer->normalize($value);

        if ($base === '') {
            $base = 'user';
        }

        $username = Str::substr($base, 0, self::MAX_LENGTH);
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $suffix++;

            $suffixValue = "-{$suffix}";
            $baseLength = self::MAX_LENGTH - strlen($suffixValue);

            $username = Str::substr($base, 0, $baseLength).$suffixValue;
        }

        return $username;
    }
}
