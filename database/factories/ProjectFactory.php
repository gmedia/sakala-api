<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    /**
     * A project lands in its creator's personal workspace unless a test names
     * another one, like a project created through the API.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Project $project): void {
            if ($project->workspace_id !== null || $project->user_id === null) {
                return;
            }

            $user = User::query()->find($project->user_id);

            if ($user !== null) {
                $project->workspace_id = app(CreatePersonalWorkspaceAction::class)->handle($user)->id;
            }
        });
    }

    /** A project owned by the workspace and created by the given user, or by its first owner. */
    public function inWorkspace(Workspace $workspace, ?User $creator = null): static
    {
        return $this->state(fn (): array => [
            'workspace_id' => $workspace->id,
            'user_id' => $creator !== null ? $creator->id : $workspace->members()->orderBy('created_at')->value('user_id'),
        ]);
    }

    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'slug' => $slug,
            'repository_provider' => 'github',
            'thumbnail_url' => null,
            'repository_url' => "https://github.com/sakala-demo/{$slug}",
            'repository_full_name' => "sakala-demo/{$slug}",
            'branch' => 'main',
            'default_domain' => "{$slug}.run.sakala.localhost",
            'status' => ProjectStatus::Draft,
            'runtime_status' => RuntimeStatus::NotDeployed,
            'detected_port' => null,
            'last_deployed_at' => null,
        ];
    }
}
