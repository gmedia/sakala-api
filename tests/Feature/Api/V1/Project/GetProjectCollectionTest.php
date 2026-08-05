<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Authenticated user can get own project collection', function () {
    $user = User::factory()->create();

    Project::factory()->count(3)->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user, 'web');

    $this->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'repository_full_name',
                    'branch',
                    'runtime_status',
                    'last_deployed_at',
                    'created_at',
                ],
            ],
            'links',
            'meta',
        ]);
});

test('User only sees own projects', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Project::factory()->count(2)->create([
        'user_id' => $user->id,
    ]);

    Project::factory()->count(4)->create([
        'user_id' => $otherUser->id,
    ]);

    $this->actingAs($user, 'web');

    $this->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('Authenticated user gets empty collection when no projects exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

test('Project collection is paginated', function () {
    $user = User::factory()->create();

    Project::factory()->count(7)->create([
        'user_id' => $user->id,
    ]);

    $this->actingAs($user, 'web');

    $this->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonCount(6, 'data')
        ->assertJsonPath('meta.total', 7)
        ->assertJsonPath('meta.per_page', 6)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 2);
});

test('User can filter projects from last 7 days', function () {
    $user = User::factory()->create();

    Project::factory()->create([
        'user_id' => $user->id,
        'created_at' => now()->subDays(3),
    ]);

    Project::factory()->create([
        'user_id' => $user->id,
        'created_at' => now()->subDays(10),
    ]);

    $this->actingAs($user, 'web');

    $this->getJson('/api/v1/app/projects?filter=7_days')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
