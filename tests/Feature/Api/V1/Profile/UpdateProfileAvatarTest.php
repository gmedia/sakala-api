<?php

declare(strict_types=1);

use App\Actions\Profile\UpdateProfileAction;
use App\Data\Profile\ProfileData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('authenticated user can update their avatar', function () {
    Storage::fake('avatars');

    $user = User::factory()->create([
        'avatar_path' => null,
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

    $response->assertSuccessful();

    $user->refresh();

    expect($user->avatar_path)
        ->toStartWith('avatars/');

    Storage::disk('avatars')
        ->assertExists($user->avatar_path);
});

test('authenticated user can replace their avatar', function () {
    Storage::fake('avatars');

    $oldAvatarPath = 'avatars/old-avatar.jpg';

    Storage::disk('avatars')->put(
        $oldAvatarPath,
        'old avatar',
    );

    $user = User::factory()->create([
        'avatar_path' => $oldAvatarPath,
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

    expect($user->avatar_path)
        ->toStartWith('avatars/')
        ->not->toBe($oldAvatarPath);

    Storage::disk('avatars')
        ->assertExists($user->avatar_path);

    Storage::disk('avatars')
        ->assertMissing($oldAvatarPath);
});

test('returns external avatar url when user has no stored avatar', function () {
    $user = User::factory()->create([
        'avatar_url' => 'https://avatars.githubusercontent.com/u/123',
        'avatar_path' => null,
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.user'));

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.avatar_url',
            'https://avatars.githubusercontent.com/u/123',
        );
});

test('returns stored avatar url when user has both external and stored avatar', function () {
    Storage::fake('avatars');

    $avatarPath = 'avatars/avatar.jpg';

    Storage::disk('avatars')->put($avatarPath, 'avatar');

    $user = User::factory()->create([
        'avatar_url' => 'https://avatars.githubusercontent.com/u/123',
        'avatar_path' => $avatarPath,
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.user'));

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.avatar_url',
            Storage::disk('avatars')->url($avatarPath),
        );
});

test('does not expose avatar path in profile response', function () {
    Storage::fake('avatars');

    $avatarPath = 'avatars/avatar.jpg';

    Storage::disk('avatars')->put($avatarPath, 'avatar');

    $user = User::factory()->create([
        'avatar_path' => $avatarPath,
    ]);

    $this
        ->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonMissingPath('data.avatar_path');
});

test('cleans up new avatar when profile update fails', function () {
    Storage::fake('avatars');

    $oldAvatarPath = 'avatars/old-avatar.jpg';

    Storage::disk('avatars')->put(
        $oldAvatarPath,
        'old avatar',
    );

    $user = User::factory()->create([
        'avatar_path' => $oldAvatarPath,
    ]);

    User::saving(function () {
        return false;
    });

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

    $response->assertServerError();

    $user->refresh();

    expect($user->avatar_path)
        ->toBe($oldAvatarPath);

    Storage::disk('avatars')
        ->assertExists($oldAvatarPath);

    expect(
        Storage::disk('avatars')->allFiles('avatars')
    )->toBe([$oldAvatarPath]);
});

test('uses the latest avatar path when updating from a stale user snapshot', function () {
    Storage::fake('avatars');

    $oldAvatarPath = 'avatars/old-avatar.jpg';

    Storage::disk('avatars')->put(
        $oldAvatarPath,
        'old avatar',
    );

    $user = User::factory()->create([
        'avatar_path' => $oldAvatarPath,
    ]);

    $staleUserOne = $user->fresh();
    $staleUserTwo = $user->fresh();

    $action = app(UpdateProfileAction::class);

    $firstAvatar = UploadedFile::fake()->create(
        'first-avatar.jpg',
        100,
        'image/jpeg',
    );

    $firstUpdatedUser = $action->handle(
        $staleUserOne,
        new ProfileData(avatar: $firstAvatar),
    );

    $firstAvatarPath = $firstUpdatedUser->avatar_path;

    expect($firstAvatarPath)
        ->not->toBe($oldAvatarPath);

    Storage::disk('avatars')
        ->assertExists($firstAvatarPath);

    $secondAvatar = UploadedFile::fake()->create(
        'second-avatar.jpg',
        100,
        'image/jpeg',
    );

    $secondUpdatedUser = $action->handle(
        $staleUserTwo,
        new ProfileData(avatar: $secondAvatar),
    );

    $secondAvatarPath = $secondUpdatedUser->avatar_path;

    expect($secondAvatarPath)
        ->not->toBe($firstAvatarPath)
        ->not->toBe($oldAvatarPath);

    $user->refresh();

    expect($user->avatar_path)
        ->toBe($secondAvatarPath);

    Storage::disk('avatars')
        ->assertMissing($oldAvatarPath);

    Storage::disk('avatars')
        ->assertMissing($firstAvatarPath);

    Storage::disk('avatars')
        ->assertExists($secondAvatarPath);
});

test('keeps existing avatar when updating profile without avatar', function () {
    Storage::fake('avatars');

    $user = User::factory()->create([
        'avatar_path' => 'avatars/existing-avatar.jpg',
    ]);

    Storage::disk('avatars')->put(
        'avatars/existing-avatar.jpg',
        'avatar',
    );

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'name' => 'Updated Name',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.avatar_path', null);

    $user->refresh();

    expect($user->avatar_path)
        ->toBe('avatars/existing-avatar.jpg');

    Storage::disk('avatars')
        ->assertExists('avatars/existing-avatar.jpg');
});
