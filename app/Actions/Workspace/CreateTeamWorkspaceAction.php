<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Exceptions\Workspace\EmailVerificationRequiredException;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Workspace\WorkspaceAudit;
use App\Support\Workspace\WorkspaceSlugGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Any user with a verified email may create teams, up to the number of teams
 * they may own. The count runs under the user row lock, so two requests cannot
 * both pass the last slot. Admins are not capped, as with project quotas.
 */
final class CreateTeamWorkspaceAction
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly WorkspaceSlugGenerator $slugs,
    ) {}

    public function handle(User $user, string $name, ?string $slug = null): Workspace
    {
        if (! $user->hasVerifiedEmail()) {
            throw new EmailVerificationRequiredException;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                return DB::transaction(function () use ($user, $name, $slug, $attempt): Workspace {
                    WorkspaceOwnership::ensureCanOwnAnotherTeam($user);

                    $workspace = Workspace::query()->create([
                        'name' => $name,
                        'slug' => $slug ?? $this->slugs->generate($name, $attempt),
                        'type' => WorkspaceType::Team,
                    ]);

                    WorkspaceMember::query()->create([
                        'workspace_id' => $workspace->id,
                        'user_id' => $user->id,
                        'role' => WorkspaceRole::Owner,
                    ]);

                    WorkspaceAudit::record($user, 'workspace.created', $workspace, ['type' => WorkspaceType::Team->value]);

                    return $workspace;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // A slug the caller chose is theirs to change; a generated one is retried.
                if ($slug !== null) {
                    throw ValidationException::withMessages(['slug' => [__('validation.unique', ['attribute' => 'slug'])]]);
                }

                if ($attempt + 1 >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
