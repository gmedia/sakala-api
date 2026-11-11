<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

/** An admin account, with a personal workspace like every factory user. */
function adminApiAdmin(array $attributes = []): User
{
    return User::factory()->create(['role' => UserRole::Admin, ...$attributes]);
}
