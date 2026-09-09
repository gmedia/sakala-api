<?php

declare(strict_types=1);

namespace App\Support\User;

use App\Models\User;
use Illuminate\Support\Str;

class UsernameGenerator
{
    private const MAX_LENGTH = 50;

    private const MAX_SUFFIX_ATTEMPTS = 1000;

    private function withSuffix(string $base, int $suffix): string
    {
        $suffixValue = "-{$suffix}";
        $baseLength = self::MAX_LENGTH - strlen($suffixValue);

        $truncatedBase = $this->truncate($base, max($baseLength, 0));

        if ($truncatedBase === '') {
            $truncatedBase = $this->truncate('user', max($baseLength, 1));
        }

        return $truncatedBase.$suffixValue;
    }

    private function withRandomTail(string $base): string
    {
        $tail = '-'.Str::lower(Str::random(6));
        $baseLength = self::MAX_LENGTH - strlen($tail);
        $truncatedBase = $this->truncate($base, max($baseLength, 0));

        if ($truncatedBase === '') {
            $truncatedBase = 'user';
        }

        return $truncatedBase.$tail;
    }

    private function truncate(string $value, int $length): string
    {
        return rtrim(Str::substr($value, 0, $length), '-');
    }

    public function __construct(
        private readonly UsernameNormalizer $normalizer,
    ) {}

    public function generate(string $value): string
    {
        $base = $this->normalizer->normalize($value);

        if ($base === '') {
            $base = 'user';
        }

        $username = $this->truncate($base, self::MAX_LENGTH);
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $suffix++;

            if ($suffix > self::MAX_SUFFIX_ATTEMPTS) {
                $username = $this->withRandomTail($base);
                break;
            }

            $username = $this->withSuffix($base, $suffix);
        }

        return $username;
    }

    public function generateAfterCollision(string $value, int $attempt): string
    {
        $base = $this->normalizer->normalize($value);

        if ($base === '') {
            $base = 'user';
        }

        return $attempt > self::MAX_SUFFIX_ATTEMPTS
            ? $this->withRandomTail($base)
            : $this->withSuffix($base, $attempt + 1);
    }
}
