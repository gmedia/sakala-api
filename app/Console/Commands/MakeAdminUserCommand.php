<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\ChangeUserRoleAction;
use App\Actions\Admin\CreateAdminUserAction;
use App\Enums\UserRole;
use App\Exceptions\Admin\LastAdminException;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The documented way to get the first admin, to promote later ones, and to
 * demote one, without tinker. It never creates an account unless asked to
 * with --create, and never changes the password or sign-in methods of an
 * existing account. Every change writes an audit event with the operator's
 * reason, like the admin API does.
 */
final class MakeAdminUserCommand extends Command
{
    /** The admin API accepts reasons up to this length too. */
    private const MAX_REASON_LENGTH = 500;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:admin
        {email : Email of the account to promote or demote, or to create with --create}
        {--create : Create the account when no user has this email}
        {--demote : Take the admin role away from the account instead of granting it}
        {--reason= : Why, stored in the audit event (asked for in a terminal when missing)}
        {--name= : Display name for a new account (defaults to the part of the email before @)}
        {--generate-password : For a new account, generate a random password and print it once instead of prompting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Promote an existing user to admin, create a new admin account, or demote an admin';

    public function handle(ChangeUserRoleAction $changeUserRole, CreateAdminUserAction $createAdminUser): int
    {
        /** @var string $rawEmail */
        $rawEmail = $this->argument('email');
        $email = Str::lower(trim($rawEmail));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->components->error("{$rawEmail} is not a valid email address.");

            return self::FAILURE;
        }

        if ($this->option('demote') && ($this->option('create') || $this->option('generate-password') || $this->option('name') !== null)) {
            $this->components->error('--demote only takes an email and --reason. Nothing changed.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            return $this->option('demote')
                ? $this->demote($changeUserRole, $user)
                : $this->promote($changeUserRole, $user);
        }

        if (! $this->option('create') || $this->option('demote')) {
            $hint = $this->option('demote') ? '' : ' Check the address, or pass --create to create a new admin account.';
            $this->components->error("No user has the email {$email}.{$hint}");

            return self::FAILURE;
        }

        return $this->create($createAdminUser, $email);
    }

    private function promote(ChangeUserRoleAction $changeUserRole, User $user): int
    {
        if ($this->option('generate-password')) {
            $this->components->warn('--generate-password only applies to new accounts; the existing password is unchanged.');
        }

        if ($user->isAdmin()) {
            $this->components->info("{$user->email} is already an admin. Nothing changed.");

            return self::SUCCESS;
        }

        $reason = $this->reason();

        if ($reason === null) {
            return self::FAILURE;
        }

        $changeUserRole->handleFromConsole($user, UserRole::Admin, $reason);

        $this->components->info("Promoted {$user->email} (username {$user->username}) to admin.");
        $this->line('  Their password and sign-in methods are unchanged. Admin endpoints accept their session from the next request.');
        $this->line('  Recorded in the audit log as user.role_changed.');

        return self::SUCCESS;
    }

    private function demote(ChangeUserRoleAction $changeUserRole, User $user): int
    {
        if (! $user->isAdmin()) {
            $this->components->info("{$user->email} is not an admin. Nothing changed.");

            return self::SUCCESS;
        }

        $reason = $this->reason();

        if ($reason === null) {
            return self::FAILURE;
        }

        try {
            $changeUserRole->handleFromConsole($user, UserRole::User, $reason);
        } catch (LastAdminException) {
            $this->components->error("{$user->email} is the last admin. Promote another account first, then demote this one. Nothing changed.");

            return self::FAILURE;
        }

        $this->components->info("Demoted {$user->email} (username {$user->username}) to user.");
        $this->line('  Admin endpoints refuse their session from the next request.');
        $this->line('  Recorded in the audit log as user.role_changed.');

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

        $reason = $this->reason();

        if ($reason === null) {
            return self::FAILURE;
        }

        $user = $createAdminUser->handle($email, $name, $password, $reason);

        $this->components->info("Created admin account {$user->email} (username {$user->username}).");

        if ($generated) {
            $this->line('  Password, shown only this once: '.$password);
            $this->line('  Store it in a password manager now, or change it after the first sign-in.');
        }

        $this->line('  The email is marked verified. Terms acceptance and onboarding are asked on the first sign-in.');
        $this->line('  Recorded in the audit log as user.admin_created.');

        return self::SUCCESS;
    }

    /**
     * The reason from --reason, or asked for in a terminal. Only a run that
     * cannot prompt may go without one; the audit event then says so.
     */
    private function reason(): ?string
    {
        /** @var string|null $option */
        $option = $this->option('reason');
        $reason = trim($option ?? '');

        if ($reason === '' && $this->input->isInteractive()) {
            $reason = trim((string) $this->ask('Reason for this change (stored in the audit log)'));

            if ($reason === '') {
                $this->components->error('A reason is required in a terminal. Nothing changed.');

                return null;
            }
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $this->components->error('The reason may be at most '.self::MAX_REASON_LENGTH.' characters. Nothing changed.');

            return null;
        }

        return $reason;
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
