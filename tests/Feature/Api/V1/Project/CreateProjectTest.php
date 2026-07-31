<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('Authenticated user can create a project', function () {

    $user = User::factory()->create();

    Sanctum::actingAs($user);

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
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ]);
});

test('Guest cannot create a project', function () {
    $response = $this->postJson('/api/v1/app/projects', [
        'name' => 'Ichikiwir',
        'repository_url' => 'https://github.com/Ngab-Rio/ichikiwir.git',
        'branch' => 'main',
    ]);

    $response->assertUnauthorized();
});

test('Repository url must be valid github repository', function () {
    Sanctum::actingAs(User::factory()->create());

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

test('Name is required', function () {
    Sanctum::actingAs(User::factory()->create());

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
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Project',
        'repository_url' => 'https://github.com/Ngab-Rio/Karaoke-API',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'branch',
        ]);
});
