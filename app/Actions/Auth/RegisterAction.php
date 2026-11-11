<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Legal\RecordLegalAcceptanceAction;
use App\Data\Auth\RegisterData;
use App\Enums\LegalAcceptanceSource;
use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\Auth\ExistingAccountRegistrationNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class RegisterAction
{
    private const MAX_USERNAME_ATTEMPTS = 10;

    private const EXISTING_NOTICE_SECONDS = 3600;

    public function __construct(
        private CreateUserWithUniqueUsernameAction $createUserWithUniqueUsernameAction,
        private RecordLegalAcceptanceAction $recordLegalAcceptance,
    ) {}

    /**
     * Answers the same whether or not the email is taken: the person registering
     * learns nothing, and the existing account's owner gets an email instead.
     */
    public function handle(RegisterData $data): void
    {
        if (User::query()->where('email', $data->email)->exists()) {
            $this->notifyExistingOwner($data);

            return;
        }

        for ($attempt = 0; $attempt <= self::MAX_USERNAME_ATTEMPTS; $attempt++) {
            try {
                DB::transaction(function () use ($data, $attempt): void {
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

                    // Only here, where the account really is created: the answer for
                    // an email already taken is the same, and records nothing.
                    $this->recordLegalAcceptance->handle($user, LegalAcceptanceSource::Register);

                    event(new Registered($user));
                });

                return;
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isEmailCollision($exception)) {
                    $this->notifyExistingOwner($data);

                    return;
                }

                if (! $this->isUsernameCollision($exception)
                    || $attempt === self::MAX_USERNAME_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to register user.');
    }

    /**
     * Hashes anyway so this path takes as long as creating an account, and sends
     * at most one email per hour so nobody can use the form to flood a mailbox.
     */
    private function notifyExistingOwner(RegisterData $data): void
    {
        Hash::make($data->password);

        $owner = User::query()->where('email', $data->email)->first();

        if ($owner instanceof User && Cache::add('register-existing:'.sha1($data->email), true, self::EXISTING_NOTICE_SECONDS)) {
            $owner->notify(new ExistingAccountRegistrationNotification);
        }
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
