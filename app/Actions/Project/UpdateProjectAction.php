<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\UpdateProjectData;
use App\Models\Project;
use App\Models\User;
use App\Support\Domains\ProjectDomainGenerator;
use App\Support\Slug\GenerateSlug;
use App\Support\Slug\ReservedSlug;
use Illuminate\Database\DatabaseManager;

final class UpdateProjectAction
{
    public function __construct(
        protected GenerateSlug $slugGenerator,
        protected ReservedSlug $reservedSlug,
        protected ProjectDomainGenerator $domainGenerator,
        protected DatabaseManager $db,
    ) {}

    /**
     * Handle the update of a project.
     */
    public function handle(User $user, Project $project, UpdateProjectData $data): Project
    {
        return $this->db->transaction(function () use ($project, $data): Project {
            // Update the project with the new data
            $updateData = $data->toArray();

            // If name is being updated, regenerate slug and domain
            if (isset($updateData['name']) && $updateData['name'] !== $project->name) {
                $updateData['slug'] = $this->generateUniqueSlug((string) $updateData['name']);
                $updateData['default_domain'] = $this->domainGenerator->generate($updateData['slug']);
            }

            $project->update($updateData);

            return $project->fresh();
        });
    }

    /**
     * Generate a unique slug for the project.
     */
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
