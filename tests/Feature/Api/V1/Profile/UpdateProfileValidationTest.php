<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('username must be unique', function () {
    $existingUser = User::factory()->create([
        'username' => 'existing-username',
    ]);

    $user = User::factory()->create([
        'username' => 'my-username',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'username' => $existingUser->username,
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username']);
});

test('user can keep their current username', function () {
    $user = User::factory()->create([
        'username' => 'my-username',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'username' => 'my-username',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.username', 'my-username');

    expect($user->refresh()->username)
        ->toBe('my-username');
});

test('username must have a valid format', function () {
    $user = User::factory()->create([
        'username' => 'valid-username',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'username' => 'invalid_username',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username']);
});

test('username must not exceed 50 characters', function () {
    $user = User::factory()->create([
        'username' => 'valid-username',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'username' => str_repeat('a', 51),
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username']);
});

test('name must not exceed 255 characters', function () {
    $user = User::factory()->create([
        'name' => 'Valid Name',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'name' => str_repeat('a', 256),
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('avatar must be a valid image', function () {
    $user = User::factory()->create();

    $file = UploadedFile::fake()->create(
        'avatar.txt',
        100,
        'text/plain',
    );

    $response = $this
        ->actingAs($user)
        ->patch('/api/v1/app/profile', [
            'avatar' => $file,
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);
});

test('avatar must not exceed 1 MB', function () {
    $user = User::factory()->create();

    $file = UploadedFile::fake()->create(
        'avatar.jpg',
        1025,
        'image/jpeg',
    );

    $response = $this
        ->actingAs($user)
        ->patch('/api/v1/app/profile', [
            'avatar' => $file,
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['avatar']);
});

test('unauthenticated user cannot update profile', function () {
    $response = $this->patchJson('/api/v1/app/profile', [
        'name' => 'New Name',
    ]);

    $response->assertUnauthorized();
});

test('email cannot be changed through profile update', function () {
    $user = User::factory()->create([
        'email' => 'old@example.com',
        'name' => 'Old Name',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/v1/app/profile', [
            'email' => 'new@example.com',
            'name' => 'New Name',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.email', 'old@example.com');

    expect($user->refresh()->email)
        ->toBe('old@example.com');
});
