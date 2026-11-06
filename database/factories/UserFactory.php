<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Actions\Legal\RecordLegalAcceptanceAction;
use App\Enums\LegalAcceptanceSource;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->bothify('user-#####'),
            'email' => fake()->unique()->safeEmail(),
            'pending_email' => null,
            'notification_preferences' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::User,
            'avatar_url' => null,
            'avatar_path' => null,
            'onboarding_source' => null,
            'onboarding_role' => null,
            'onboarding_completed_at' => null,
            'last_login_at' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A user made by the factory has accepted the current documents, like every
     * account that can reach the app; withoutLegalAcceptance() is the exception.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            app(RecordLegalAcceptanceAction::class)->handle($user, LegalAcceptanceSource::Register);
        });
    }

    /**
     * An account that signed up through a provider and has not accepted yet.
     */
    public function withoutLegalAcceptance(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->legalAcceptances()->delete();
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
