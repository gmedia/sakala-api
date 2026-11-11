<?php

declare(strict_types=1);

namespace App\Support\Workspace;

use App\Models\Workspace;
use App\Support\User\UsernameNormalizer;
use Illuminate\Support\Str;

/**
 * Slugs share one namespace for personal and team workspaces and use the
 * username format: lowercase letters, digits, and single hyphens.
 */
final class WorkspaceSlugGenerator
{
    public const MAX_LENGTH = 50;

    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function __construct(
        private readonly UsernameNormalizer $normalizer,
    ) {}

    /**
     * A free slug close to the source. Free when checked, not reserved: the
     * unique index decides, and the caller retries with the next attempt.
     */
    public function generate(string $source, int $attempt = 0): string
    {
        $base = Str::limit($this->normalizer->normalize($source), self::MAX_LENGTH - 7, '');
        $base = trim($base, '-') === '' ? 'workspace' : trim($base, '-');

        if ($attempt === 0 && ! Workspace::query()->where('slug', $base)->exists()) {
            return $base;
        }

        for ($i = 0; $i < 5; $i++) {
            $candidate = $base.'-'.Str::lower(Str::random(6));

            if (preg_match(self::PATTERN, $candidate) === 1 && ! Workspace::query()->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
