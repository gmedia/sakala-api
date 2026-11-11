<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Workspace\WorkspaceSlugGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every account gets one personal workspace when it is created. Its slug starts
 * from the username; a team may already hold that slug, so it can differ.
 * Calling it again for the same user returns the existing workspace.
 */
final class CreatePersonalWorkspaceAction
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly WorkspaceSlugGenerator $slugs,
    ) {}

    public function handle(User $user): Workspace
    {
        $existing = Workspace::query()->where('personal_user_id', $user->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                // A savepoint when called inside a transaction, so a slug clash
                // rolls back only this attempt, on PostgreSQL too.
                return DB::transaction(function () use ($user, $attempt): Workspace {
                    $workspace = Workspace::query()->create([
                        'name' => mb_substr($user->name, 0, 100),
                        'slug' => $this->slugs->generate($user->username, $attempt),
                        'type' => WorkspaceType::Personal,
                        'personal_user_id' => $user->id,
                    ]);

                    WorkspaceMember::query()->create([
                        'workspace_id' => $workspace->id,
                        'user_id' => $user->id,
                        'role' => WorkspaceRole::Owner,
                    ]);

                    return $workspace;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt + 1 >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
