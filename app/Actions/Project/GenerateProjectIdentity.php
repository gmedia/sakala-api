<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\ProjectIdentity;
use App\Models\Project;
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
        $originalSlug = $slug;
        $counter = 1;
        // Tambahkan withTrashed() agar slug dari project yang dihapus tidak diduplikasi
        while (Project::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return Str::limit($slug, 63, '');
    }

    public function handle(string $projectName): ProjectIdentity
    {
        // Normalisasi Slug
        $baseSlug = $this->slugGenerator->fromString($projectName);

        // Tolak jika reserved word
        if ($this->reservedSlug->isReserved($baseSlug)) {
            throw ValidationException::withMessages([
                'name' => "Nama project menghasilkan identitas sistem ($baseSlug), silakan gunakan nama lain.",
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
