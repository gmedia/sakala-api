<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Auth\CreateUserWithUniqueUsernameAction;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates an admin account for an operator running `artisan user:admin
 * --create`, outside the public registration flow. The operator vouches for
 * the address, so it is marked verified; terms and onboarding are still asked
 * on the first sign-in like any other account. The account and its
 * `user.admin_created` audit event are written together.
 */
final class CreateAdminUserAction
{
    private const MAX_USERNAME_ATTEMPTS = 10;

    public function __construct(
        private CreateUserWithUniqueUsernameAction $createUserWithUniqueUsername,
    ) {}

    public function handle(string $email, string $name, string $password, string $reason): User
    {
        for ($attempt = 0; $attempt <= self::MAX_USERNAME_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($email, $name, $password, $reason, $attempt): User {
                    $user = $this->createUserWithUniqueUsername->handle(
                        attributes: [
                            'name' => $name,
                            'email' => $email,
                            'password' => $password,
                            'role' => UserRole::Admin,
                        ],
                        usernameSource: $name,
                        attempt: $attempt,
                    );

                    $user->forceFill(['email_verified_at' => now()])->save();

                    AdminAudit::recordFromConsole('user.admin_created', $user, $reason, [
                        'from' => null,
                        'to' => UserRole::Admin->value,
                    ]);

                    return $user;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Someone took the email in the meantime: not a username clash.
                if (User::query()->where('email', $email)->exists()) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to find a free username for the admin account.');
    }
}
