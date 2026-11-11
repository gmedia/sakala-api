<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\CreateAdminUserAction;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The documented way to get the first admin, and to promote later ones,
 * without tinker. It never creates an account unless asked to with --create,
 * and never changes the password or sign-in methods of an existing account.
 */
final class MakeAdminUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:admin
        {email : Email of the account to promote, or to create with --create}
        {--create : Create the account when no user has this email}
        {--name= : Display name for a new account (defaults to the part of the email before @)}
        {--generate-password : For a new account, generate a random password and print it once instead of prompting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote an existing user to admin, or create a new admin account';

    public function handle(CreateAdminUserAction $createAdminUser): int
    {
        /** @var string $rawEmail */
        $rawEmail = $this->argument('email');
        $email = Str::lower(trim($rawEmail));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->components->error("{$rawEmail} is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            return $this->promote($user);
        }

        if (! $this->option('create')) {
            $this->components->error("No user has the email {$email}. Check the address, or pass --create to create a new admin account.");

            return self::FAILURE;
        }

        return $this->create($createAdminUser, $email);
    }

    private function promote(User $user): int
    {
        if ($this->option('generate-password')) {
            $this->components->warn('--generate-password only applies to new accounts; the existing password is unchanged.');
        }

        if ($user->isAdmin()) {
            $this->components->info("{$user->email} is already an admin. Nothing changed.");

            return self::SUCCESS;
        }

        $user->update(['role' => UserRole::Admin]);

        $this->components->info("Promoted {$user->email} (username {$user->username}) to admin.");
        $this->line('  Their password and sign-in methods are unchanged. The admin pages appear after they reload the console.');

        return self::SUCCESS;
    }

    private function create(CreateAdminUserAction $createAdminUser, string $email): int
    {
        /** @var string|null $nameOption */
        $nameOption = $this->option('name');
        $name = Str::squish($nameOption ?? '');
        $name = $name !== '' ? $name : Str::before($email, '@');

        $generated = (bool) $this->option('generate-password');
        $password = $generated ? Str::password(24) : $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = $createAdminUser->handle($email, $name, $password);

        $this->components->info("Created admin account {$user->email} (username {$user->username}).");

        if ($generated) {
            $this->line('  Password, shown only this once: '.$password);
            $this->line('  Store it in a password manager now, or change it after the first sign-in.');
        }

        $this->line('  The email is marked verified. Terms acceptance and onboarding are asked on the first sign-in.');

        return self::SUCCESS;
    }

    private function askPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('A new account needs a password. Run the command in a terminal to be prompted, or pass --generate-password.');

            return null;
        }

        $password = (string) $this->secret('Password for the new admin account');
        $confirmation = (string) $this->secret('Repeat the password');

        if ($password !== $confirmation) {
            $this->components->error('The passwords do not match. Nothing was created.');

            return null;
        }

        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', Password::defaults()]]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('password').' Nothing was created.');

            return null;
        }

        return $password;
    }
}
