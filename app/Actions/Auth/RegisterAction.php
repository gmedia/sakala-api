<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\RegisterData;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
                if ($this->isEmailCollision($exception)) {
                    throw ValidationException::withMessages([
                        'email' => ['The email has already been taken.'],
                    ]);
                }

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
        $message = Str::lower($exception->getMessage());

        return $exception->index === 'users_username_unique'
            || in_array('username', $exception->columns, true)
            || str_contains($message, 'users_username_unique')
            || str_contains($message, 'users.username');
    }

    private function isEmailCollision(
        UniqueConstraintViolationException $exception,
    ): bool {
        $message = Str::lower($exception->getMessage());

        return $exception->index === 'users_email_unique'
            || in_array('email', $exception->columns, true)
            || str_contains($message, 'users_email_unique')
            || str_contains($message, 'users.email');
    }
}
