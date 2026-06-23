<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OAuthProvider;
use App\Models\OAuthAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OAuthAccount> */
class OAuthAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => OAuthProvider::Github,
            'provider_user_id' => fake()->unique()->numerify('########'),
            'provider_username' => fake()->unique()->userName(),
            'avatar_url' => fake()->imageUrl(160, 160),
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
        ];
    }
}
