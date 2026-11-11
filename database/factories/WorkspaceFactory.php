<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\LimitProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes team workspaces. Personal workspaces come with every user from
 * UserFactory, the same way registration creates them.
 *
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->bothify('team-#####'),
            'type' => WorkspaceType::Team,
            'personal_user_id' => null,
            'limit_profile_id' => null,
        ];
    }

    /** Adds the user with the role once the workspace exists. */
    public function withMember(User $user, WorkspaceRole $role = WorkspaceRole::Member): static
    {
        return $this->afterCreating(function (Workspace $workspace) use ($user, $role): void {
            WorkspaceMember::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
        });
    }

    public function ownedBy(User $user): static
    {
        return $this->withMember($user, WorkspaceRole::Owner);
    }

    public function withProfile(LimitProfile $profile): static
    {
        return $this->state(fn (): array => ['limit_profile_id' => $profile->id]);
    }
}
