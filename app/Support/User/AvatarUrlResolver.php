<?php

declare(strict_types=1);

namespace App\Support\User;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class AvatarUrlResolver
{
    public function resolve(User $user): ?string
    {
        if ($user->avatar_path !== null) {
            return Storage::disk('avatars')->url($user->avatar_path);
        }

        return $user->avatar_url;
    }
}
