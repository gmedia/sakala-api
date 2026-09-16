<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\RegisterData;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RegisterAction
{
    private const MAX_USERNAME_ATTEMPTS = 10;

    public function __construct(
        private CreateUserWithUniqueUsernameAction $createUserWithUniqueUsernameAction,
    ) {}

    public function handle(RegisterData $data): User
    {
        for ($attempt = 0; $attempt <= self::MAX_USERNAME_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $attempt): User {
                    $user = $this->createUserWithUniqueUsernameAction->handle(
                        attributes: [
                            'name' => $data->name,
                            'email' => $data->email,
                            'password' => $data->password,
                            'role' => UserRole::User,
                        ],
                        usernameSource: $data->name,
                        attempt: $attempt,
                    );

                    event(new Registered($user));

                    return $user;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (! $this->isUsernameCollision($exception)
                    || $attempt === self::MAX_USERNAME_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to register user.');
    }

    private function isUsernameCollision(
        UniqueConstraintViolationException $exception,
    ): bool {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'users_username_unique')
            || str_contains($message, 'users.username');
    }
}
