<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Data\Profile\ProfileData;
use App\Exceptions\Runtime\AvatarStorageUnavailableException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class UpdateProfileAction
{
    public function handle(
        User $user,
        ProfileData $data,
    ): User {
        $newAvatarPath = null;
        $oldAvatarPath = null;

        try {
            $updatedUser = DB::transaction(function () use ($user, $data, &$newAvatarPath, &$oldAvatarPath): User {
                $user = User::query()
                    ->lockForUpdate()
                    ->findOrFail($user->id);

                $oldAvatarPath = $user->avatar_path;

                if ($data->name !== null) {
                    $user->name = $data->name;
                }

                if ($data->username !== null) {
                    $user->username = $data->username;
                }

                if ($data->avatar !== null) {
                    // A missing bucket or unreachable store reads as a clear 503.
                    $storedAvatarPath = rescue(
                        static fn (): string|false => $data->avatar->store('avatars', 'avatars'),
                        false,
                    );

                    if ($storedAvatarPath === false) {
                        throw new AvatarStorageUnavailableException;
                    }

                    $newAvatarPath = $storedAvatarPath;
                    $user->avatar_path = $newAvatarPath;
                }

                if (! $user->save()) {
                    throw new RuntimeException('Failed to update user profile');
                }

                return $user;
            });
        } catch (Throwable $th) {
            if ($newAvatarPath !== null) {
                try {
                    if (! Storage::disk('avatars')->delete($newAvatarPath)) {
                        Log::warning('Failed to clean up new avatar.', [
                            'user_id' => $user->id,
                            'avatar_path' => $newAvatarPath,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::warning('Failed to clean up new avatar.', [
                        'user_id' => $user->id,
                        'avatar_path' => $newAvatarPath,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            throw $th;
        }

        if ($oldAvatarPath !== null && $newAvatarPath !== null && $oldAvatarPath !== $newAvatarPath) {
            try {
                if (! Storage::disk('avatars')->delete($oldAvatarPath)) {
                    Log::warning('Failed to delete old avatar. ', [
                        'user_id' => $user->id,
                        'avatar_path' => $oldAvatarPath,
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Failed to delete old avatar. ', [
                    'user_id' => $user->id,
                    'avatar_path' => $oldAvatarPath,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $updatedUser->refresh();
    }
}
