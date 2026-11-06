<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class RemoveAvatarAction
{
    /**
     * Clears the uploaded avatar and the one copied from an OAuth provider too;
     * leaving that one would make the old picture come back. Removing an avatar
     * that is already gone is a no-op.
     */
    public function handle(User $user): User
    {
        $path = $user->avatar_path;

        $user->forceFill(['avatar_path' => null, 'avatar_url' => null])->save();

        if ($path !== null) {
            try {
                if (! Storage::disk('avatars')->delete($path)) {
                    Log::warning('Failed to delete removed avatar.', ['user_id' => $user->id, 'avatar_path' => $path]);
                }
            } catch (Throwable $exception) {
                Log::warning('Failed to delete removed avatar.', [
                    'user_id' => $user->id,
                    'avatar_path' => $path,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $user->refresh();
    }
}
