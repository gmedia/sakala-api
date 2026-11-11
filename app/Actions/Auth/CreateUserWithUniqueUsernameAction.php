<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Models\User;
use App\Support\User\UsernameGenerator;
use Illuminate\Support\Facades\DB;

class CreateUserWithUniqueUsernameAction
{
    public function __construct(
        private UsernameGenerator $usernameGenerator,
        private CreatePersonalWorkspaceAction $createPersonalWorkspace,
    ) {}

    /**
     * Creates the account together with its personal workspace, so no user
     * ever exists without one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        array $attributes,
        string $usernameSource,
        int $attempt = 0,
    ): User {
        $username = $attempt === 0
            ? $this->usernameGenerator->generate($usernameSource)
            : $this->usernameGenerator->generateAfterCollision(
                $usernameSource,
                $attempt,
            );

        return DB::transaction(function () use ($attributes, $username): User {
            $user = User::query()->create([
                ...$attributes,
                'username' => $username,
            ]);

            $this->createPersonalWorkspace->handle($user);

            return $user;
        });
    }
}
