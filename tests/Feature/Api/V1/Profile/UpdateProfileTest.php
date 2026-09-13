<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated user can update their name', function () {
    $user = User::factory()->create([
        'name' => 'Reza Auditore',
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->patchJson('/api/v1/app/profile', [
            'name' => 'Sakala User',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Sakala User');

    expect($user->refresh()->name)
        ->toBe('Sakala User');
});

test('authenticated user can update their username', function () {
    $user = User::factory()->create([
        'username' => 'old-username',
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->patchJson('/api/v1/app/profile', [
            'username' => 'new-username',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.username', 'new-username');

    expect($user->refresh()->username)
        ->toBe('new-username');
});

test('authenticated user can update multiple profile fields', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'username' => 'old-username',
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->patchJson('/api/v1/app/profile', [
            'name' => 'Black Panther',
            'username' => 'panther-hitam',
        ]);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Black Panther')
        ->assertJsonPath('data.username', 'panther-hitam');

    expect($user->refresh()->name)
        ->toBe('Black Panther');

    expect($user->refresh()->username)
        ->toBe('panther-hitam');
});
