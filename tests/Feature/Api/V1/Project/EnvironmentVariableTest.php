<?php

declare(strict_types=1);

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('user can create an environment variable', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $response = $this->actingAs($user, 'web')
        ->postJson(
            route('api.v1.app.projects.environment-variables.store', $project),
            [
                'key' => 'APP_ENV',
                'value' => 'production',
                'is_secret' => true,
            ],
        );

    $response
        ->assertCreated()
        ->assertJsonPath('data.key', 'APP_ENV')
        ->assertJsonPath('data.is_secret', true)
        ->assertJsonMissingPath('data.value')
        ->assertJsonMissingPath('data.encrypted_value');

    $this->assertDatabaseHas('environment_variables', [
        'project_id' => $project->id,
        'key' => 'APP_ENV',
    ]);

    $rawValue = DB::table('environment_variables')
        ->where('project_id', $project->id)
        ->where('key', 'APP_ENV')
        ->value('encrypted_value');

    expect($rawValue)
        ->not->toBe('production');

    expect(Crypt::decryptString($rawValue))
        ->toBe('production');
});

test('user can list environment variables without exposing values', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $environmentVariable = EnvironmentVariable::factory()
        ->for($project)
        ->create([
            'key' => 'APP_KEY',
            'encrypted_value' => 'super-secret-value',
        ]);

    $response = $this
        ->actingAs($user, 'web')
        ->getJson(
            route(
                'api.v1.app.projects.environment-variables.index',
                $project,
            ),
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.0.id', $environmentVariable->id)
        ->assertJsonPath('data.0.key', 'APP_KEY')
        ->assertJsonMissingPath('data.0.value')
        ->assertJsonMissingPath('data.0.encrypted_value')
        ->assertDontSee('super-secret-value');
});

test('user can delete an environment variable', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $environmentVariable = EnvironmentVariable::factory()
        ->for($project)
        ->create();

    $response = $this
        ->actingAs($user, 'web')
        ->deleteJson(
            route(
                'api.v1.app.projects.environment-variables.destroy',
                [
                    'project' => $project,
                    'environmentVariable' => $environmentVariable,
                ],
            ),
        );

    $response->assertNoContent();

    $this->assertDatabaseMissing('environment_variables', [
        'id' => $environmentVariable->id,
    ]);
});

test('user can reveal an environment variable value', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $environmentVariable = EnvironmentVariable::factory()
        ->for($project)
        ->create([
            'key' => 'APP_KEY',
            'encrypted_value' => 'super-secret-value',
        ]);

    $response = $this
        ->actingAs($user, 'web')
        ->getJson(
            route(
                'api.v1.app.projects.environment-variables.value',
                [
                    'project' => $project,
                    'environmentVariable' => $environmentVariable,
                ],
            ),
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.value', 'super-secret-value');
});

test('user cannot access environment variables from another users project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->for($owner)->create();

    $this
        ->actingAs($otherUser, 'web')
        ->getJson(
            route(
                'api.v1.app.projects.environment-variables.index',
                $project,
            ),
        )
        ->assertForbidden();
});

test('user cannot reveal an environment variable from another users project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->for($owner)->create();

    $environmentVariable = EnvironmentVariable::factory()
        ->for($project)
        ->create([
            'key' => 'APP_KEY',
            'encrypted_value' => 'super-secret-value',
        ]);

    $this
        ->actingAs($otherUser, 'web')
        ->getJson(
            route(
                'api.v1.app.projects.environment-variables.value',
                [
                    'project' => $project,
                    'environmentVariable' => $environmentVariable,
                ],
            ),
        )
        ->assertForbidden();
});

test('user cannot delete an environment variable from another users project', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->for($owner)->create();

    $environmentVariable = EnvironmentVariable::factory()
        ->for($project)
        ->create();

    $this
        ->actingAs($otherUser, 'web')
        ->deleteJson(
            route(
                'api.v1.app.projects.environment-variables.destroy',
                [
                    'project' => $project,
                    'environmentVariable' => $environmentVariable,
                ],
            ),
        )
        ->assertForbidden();

    $this->assertDatabaseHas('environment_variables', [
        'id' => $environmentVariable->id,
    ]);
});

test('duplicate environment variable key is rejected within the same project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    EnvironmentVariable::factory()
        ->for($project)
        ->create([
            'key' => 'APP_ENV',
        ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            route(
                'api.v1.app.projects.environment-variables.store',
                $project,
            ),
            [
                'key' => 'APP_ENV',
                'value' => 'another-value',
                'is_secret' => true,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['key']);
});

test('same environment variable key is allowed on different projects', function (): void {
    $user = User::factory()->create();

    $projectA = Project::factory()->for($user)->create();
    $projectB = Project::factory()->for($user)->create();

    EnvironmentVariable::factory()
        ->for($projectA)
        ->create([
            'key' => 'APP_ENV',
        ]);

    $this
        ->actingAs($user, 'web')
        ->postJson(
            route(
                'api.v1.app.projects.environment-variables.store',
                $projectB,
            ),
            [
                'key' => 'APP_ENV',
                'value' => 'production',
                'is_secret' => true,
            ],
        )
        ->assertCreated();
});

test('environment variable key and value are validated', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this
        ->actingAs($user, 'web')
        ->postJson(
            route(
                'api.v1.app.projects.environment-variables.store',
                $project,
            ),
            [
                'key' => 'invalid-key',
                'value' => '',
                'is_secret' => true,
            ],
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'key',
            'value',
        ]);
});

test('environment variable cannot be accessed through another project binding', function (): void {
    $user = User::factory()->create();

    $projectA = Project::factory()->for($user)->create();
    $projectB = Project::factory()->for($user)->create();

    $environmentVariable = EnvironmentVariable::factory()
        ->for($projectB)
        ->create();

    $this
        ->actingAs($user, 'web')
        ->getJson(
            route(
                'api.v1.app.projects.environment-variables.value',
                [
                    'project' => $projectA,
                    'environmentVariable' => $environmentVariable,
                ],
            ),
        )
        ->assertNotFound();
});
