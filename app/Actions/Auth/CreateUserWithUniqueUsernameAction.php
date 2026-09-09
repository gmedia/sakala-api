<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\User\UsernameGenerator;

class CreateUserWithUniqueUsernameAction
{
    public function __construct(
        private UsernameGenerator $usernameGenerator,
    ) {}

    /**
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

        return User::query()->create([
            ...$attributes,
            'username' => $username,
        ]);
    }
}
