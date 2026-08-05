<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Authenticated user can create a project', function () {

    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $response = $this->postJson('/api/v1/app/projects', [
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ]);

    $response
        ->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'repository_full_name',
                'branch',
                'runtime_status',
                'created_at',
            ],
        ]);
    $this->assertDatabaseHas('projects', [
        'user_id' => $user->id,
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir',
        'branch' => 'main',
    ]);
});

test('Guest cannot create a project', function () {
    $response = $this->postJson('/api/v1/app/projects', [
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir',
        'branch' => 'main',
    ]);

    $response->assertUnauthorized();
});

test('Repository url must be string', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => [],
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('Repository url must be valid github repository', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://gitlab.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('Repository url must use https', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'http://github.com/Ngab-Rio/Karaoke-API',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('Repository url must not contain query string', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API?foo=bar',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('Repository url must not contain fragment', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API#readme',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('Repository URL is stored in canonical format', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://www.github.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ])->assertCreated();

    $this->assertDatabaseHas('projects', [
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir',
    ]);
});

test('Repository url must not contain credentials', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://user:password@github.com/Ngab-Rio/Karaoke-API',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'repository_url',
        ]);
});

test('Name is required', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API',
        'branch' => 'main',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'name',
        ]);
});

test('Branch is required', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);
});
