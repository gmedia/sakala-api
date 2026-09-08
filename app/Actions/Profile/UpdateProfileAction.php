<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Data\Profile\ProfileData;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class UpdateProfileAction
{
    public function handle(
        User $user,
        ProfileData $data,
    ): User {
        $oldAvatarPath = $user->avatar_url;

        if ($data->name !== null) {
            $user->name = $data->name;
        }

        if ($data->username !== null) {
            $user->username = $data->username;
        }

        if ($data->avatar !== null) {
            $newAvatarPath = $data->avatar->store('avatars', config('filesystems.default'));

            if ($newAvatarPath === false) {
                throw new RuntimeException('Failed to store avatar.');
            }

            $user->avatar_url = $newAvatarPath;
        }

        $user->save();

        if ($data->avatar !== null && $oldAvatarPath !== null && $oldAvatarPath !== $user->avatar_url) {
            Storage::disk(config('filesystems.default'))->delete($oldAvatarPath);
        }

        return $user->refresh();
    }
}
