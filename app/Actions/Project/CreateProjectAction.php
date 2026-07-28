<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\CreateProjectData;
use App\Models\Project;
use App\Models\User;
use App\Support\Domains\ProjectDomainGenerator;
use App\Support\Slug\GenerateSlug;
use App\Support\Slug\ReservedSlug;
use Illuminate\Database\DatabaseManager;

final class CreateProjectAction
{
    public function __construct(
        protected GenerateSlug $slugGenerator,
        protected ReservedSlug $reservedSlug,
        protected ProjectDomainGenerator $domainGenerator,
        protected DatabaseManager $db,
    ) {}

    /**
     * Handle the creation of a new project.
     */
    public function handle(User $user, CreateProjectData $data): Project
    {
        return $this->db->transaction(function () use ($user, $data): Project {
            $slug = $this->generateUniqueSlug($data->name);
            $defaultDomain = $this->domainGenerator->generate($slug);

            $project = Project::create([
                'user_id' => $user->id,
                'name' => $data->name,
                'slug' => $slug,
                'repository_provider' => $data->repositoryProvider ?? 'github',
                'repository_url' => $data->repositoryUrl,
                'repository_full_name' => $data->repositoryFullName,
                'branch' => $data->branch,
                'default_domain' => $defaultDomain,
            ]);

            return $project->fresh();
        });
    }

    /**
     * Generate a unique slug for the project.
     */
    protected function generateUniqueSlug(string $name): string
    {
        $baseSlug = $this->slugGenerator->fromString($name);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is not reserved
        if ($this->reservedSlug->isReserved($slug)) {
            $slug = $this->slugGenerator->fromString($name.'-'.$counter);
        }

        // Check for collision with existing projects
        while (Project::where('slug', $slug)->exists()) {
            $slug = $this->slugGenerator->fromString($name.'-'.$counter);
            $counter++;
        }

        return $slug;
    }
}
