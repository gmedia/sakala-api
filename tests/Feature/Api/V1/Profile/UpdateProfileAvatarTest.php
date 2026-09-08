<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('authenticated user can update their avatar', function () {
    Storage::fake('local');

    $user = User::factory()->create([
        'avatar_url' => null,
    ]);

    $file = UploadedFile::fake()->create(
        'avatar.jpg',
        100,
        'image/jpeg',
    );

    $response = $this
        ->actingAs($user, 'web')
        ->patch('/api/v1/app/profile', [
            'avatar' => $file,
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath(
            'data.avatar_url',
            fn ($value) => str_starts_with($value, 'avatars/')
        );

    $user->refresh();

    expect($user->avatar_url)
        ->toStartWith('avatars/');

    Storage::disk('local')
        ->assertExists($user->avatar_url);
});

test('authenticated user can replace their avatar', function () {
    Storage::fake('local');

    $oldAvatarPath = 'avatars/old-avatar.jpg';

    Storage::disk('local')->put(
        $oldAvatarPath,
        'old avatar',
    );

    $user = User::factory()->create([
        'avatar_url' => $oldAvatarPath,
    ]);

    $newAvatar = UploadedFile::fake()->create(
        'new-avatar.jpg',
        100,
        'image/jpeg',
    );

    $response = $this
        ->actingAs($user, 'web')
        ->patch('/api/v1/app/profile', [
            'avatar' => $newAvatar,
        ]);

    $response->assertSuccessful();

    $user->refresh();

    expect($user->avatar_url)
        ->toStartWith('avatars/')
        ->not->toBe($oldAvatarPath);

    Storage::disk('local')
        ->assertExists($user->avatar_url);

    Storage::disk('local')
        ->assertMissing($oldAvatarPath);
});
