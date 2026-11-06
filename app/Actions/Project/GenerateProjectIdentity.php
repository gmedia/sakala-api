<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\ProjectIdentity;
use App\Models\Project;
use App\Models\RetiredProjectSlug;
use App\Support\Domains\ProjectDomainGenerator;
use App\Support\Slug\GenerateSlug;
use App\Support\Slug\ReservedSlug;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GenerateProjectIdentity
{
    public function __construct(
        protected GenerateSlug $slugGenerator,
        protected ReservedSlug $reservedSlug,
        protected ProjectDomainGenerator $domainGenerator
    ) {}

    protected function resolveCollision(string $slug): string
    {
        $maxLength = 63;

        $candidateSlug = Str::limit($slug, $maxLength, '');

        if (! $this->isTaken($candidateSlug)) {
            return $candidateSlug;
        }

        $counter = 1;

        do {
            $suffix = '-'.$counter;

            $base = Str::limit(
                $slug,
                $maxLength - strlen($suffix),
                ''
            );

            $candidateSlug = $base.$suffix;
            $counter++;
        } while ($this->isTaken($candidateSlug));

        return $candidateSlug;
    }

    /**
     * Projects first: a purge moves the slug to the retired list in one commit,
     * so reading in this order cannot miss it in both places.
     */
    private function isTaken(string $slug): bool
    {
        return Project::withTrashed()->where('slug', $slug)->exists()
            || RetiredProjectSlug::query()->whereKey($slug)->exists();
    }

    public function handle(string $projectName): ProjectIdentity
    {
        // Normalisasi Slug
        $baseSlug = $this->slugGenerator->fromString($projectName);

        // Tolak jika reserved word
        if ($this->reservedSlug->isReserved($baseSlug)) {
            throw ValidationException::withMessages([
                'name' => 'Nama project tersebut tidak dapat digunakan karena menghasilkan slug yang dicadangkan sistem. Silakan gunakan nama lain.',
            ]);
        }

        // Resolusi Collision
        $uniqueSlug = $this->resolveCollision($baseSlug);

        // Generate Domain
        $defaultDomain = $this->domainGenerator->generate($uniqueSlug);

        return new ProjectIdentity(
            slug: $uniqueSlug,
            defaultDomain: $defaultDomain
        );
    }
}
