<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: initial']],
        ], 200),
    ]);
});

test('build configuration is accepted on store', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Web App',
            'repository_url' => 'https://github.com/example/web-app',
            'branch' => 'main',
            'configured_port' => 8080,
            'build_command' => 'npm run build',
            'start_command' => 'node server.js',
            'root_directory' => 'apps/web',
        ])
        ->assertCreated()
        ->assertJsonPath('data.configured_port', 8080)
        ->assertJsonPath('data.effective_port', 8080)
        ->assertJsonPath('data.build_command', 'npm run build')
        ->assertJsonPath('data.start_command', 'node server.js')
        ->assertJsonPath('data.root_directory', 'apps/web');

    $project = Project::query()->findOrFail($response->json('data.id'));

    expect($project->configured_port)->toBe(8080);
});

test('a project without configuration defaults everything off', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Plain App',
            'repository_url' => 'https://github.com/example/plain-app',
            'branch' => 'main',
        ])
        ->assertCreated()
        ->assertJsonPath('data.configured_port', null)
        ->assertJsonPath('data.effective_port', 3000)
        ->assertJsonPath('data.build_command', null)
        ->assertJsonPath('data.auto_deploy', false);
});

test('build configuration updates partially and clears with null', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['build_command' => 'make', 'start_command' => 'run']);

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['build_command' => null, 'configured_port' => 9000])
        ->assertOk()
        ->assertJsonPath('data.build_command', null)
        ->assertJsonPath('data.start_command', 'run')
        ->assertJsonPath('data.configured_port', 9000)
        ->assertJsonPath('data.effective_port', 9000);
});

test('effective_port prefers configured over detected over the default', function (?int $configured, ?int $detected, int $expected): void {
    $project = Project::factory()->create(['configured_port' => $configured, 'detected_port' => $detected]);

    expect($project->effectivePort())->toBe($expected);
})->with([
    [8080, 5000, 8080],
    [null, 5000, 5000],
    [null, null, 3000],
    [65535, null, 65535],
]);

test('invalid build configuration is rejected', function (string $field, mixed $value): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", [$field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($project->fresh()->{$field})->toBeNull();
})->with([
    'port zero' => ['configured_port', 0],
    'port too high' => ['configured_port', 65536],
    'port not a number' => ['configured_port', 'http'],
    'multi-line build' => ['build_command', "npm ci\nrm -rf /"],
    'carriage return' => ['start_command', "node a\rnode b"],
    'null byte' => ['build_command', "npm\0run"],
    'tab' => ['start_command', "node\tserver"],
    'oversized command' => ['build_command', str_repeat('a', 1001)],
    'absolute path' => ['root_directory', '/etc'],
    'parent escape' => ['root_directory', '../secrets'],
    'nested escape' => ['root_directory', 'apps/../../secrets'],
    'trailing escape' => ['root_directory', 'apps/..'],
    'current dir segment' => ['root_directory', './apps'],
    'dot only' => ['root_directory', '.'],
    'trailing slash' => ['root_directory', 'apps/'],
    'backslash' => ['root_directory', 'apps\\web'],
    'shell characters' => ['root_directory', 'apps;rm'],
    'oversized path' => ['root_directory', str_repeat('a', 256)],
]);

test('sensible directories are accepted', function (string $directory): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['root_directory' => $directory])
        ->assertOk()
        ->assertJsonPath('data.root_directory', $directory);
})->with(['apps/web', 'web', 'packages/my_app-1.0', '.config/app']);

test('another user cannot change build configuration', function (): void {
    $project = Project::factory()->create();

    $this->actingAs(User::factory()->create(), 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['build_command' => 'make'])
        ->assertForbidden();

    expect($project->fresh()->build_command)->toBeNull();
});

test('the deploy payload carries configuration only when it is set', function (): void {
    $user = User::factory()->create();
    $configured = Project::factory()->for($user)->create([
        'branch' => 'main',
        'configured_port' => 8080,
        'build_command' => 'npm run build',
        'start_command' => 'node server.js',
        'root_directory' => 'apps/web',
    ]);
    $plain = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web');

    $this->postJson("/api/v1/app/projects/{$configured->id}/deployments", ['branch' => 'main'])->assertCreated();
    $this->postJson("/api/v1/app/projects/{$plain->id}/deployments", ['branch' => 'main'])->assertCreated();

    expect(AgentCommand::query()->where('project_id', $configured->id)->sole()->payload)
        ->toMatchArray([
            'container_port' => 8080,
            'build_command' => 'npm run build',
            'start_command' => 'node server.js',
            'root_directory' => 'apps/web',
        ]);

    expect(AgentCommand::query()->where('project_id', $plain->id)->sole()->payload)
        ->not->toHaveKeys(['build_command', 'start_command', 'root_directory'])
        ->toMatchArray(['container_port' => 3000]);
});

test('a port change asks for a redeploy until a new deployment exists', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['branch' => 'main']);
    $live = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'created_at' => now()->subHour(),
    ]);
    $project->update(['active_deployment_id' => $live->id]);

    $this->actingAs($user, 'web');

    $this->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.redeploy_required', false);

    $this->patchJson("/api/v1/app/projects/{$project->id}", ['configured_port' => 8080])
        ->assertOk()
        ->assertJsonPath('data.effective_port', 8080)
        ->assertJsonPath('data.redeploy_required', true);

    $this->travel(5)->seconds();

    $new = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 2]);
    $project->update(['active_deployment_id' => $new->id]);

    $this->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.redeploy_required', false);
});

test('saving the same configuration or renaming asks for nothing', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['configured_port' => 8080]);
    $live = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1]);
    $project->update(['active_deployment_id' => $live->id]);

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['configured_port' => 8080, 'name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.redeploy_required', false);

    expect($project->fresh()->config_changed_at)->toBeNull();
});

test('a project nothing serves never asks for a redeploy', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['configured_port' => 8080])
        ->assertJsonPath('data.redeploy_required', false);
});

// ─── Editing environment variables ───────────────────────────────────────────

test('is_secret comes from the request and defaults to secret', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web');

    $this->postJson(route('api.v1.app.projects.environment-variables.store', $project), [
        'key' => 'APP_ENV', 'value' => 'production', 'is_secret' => false,
    ])
        ->assertCreated()
        ->assertJsonPath('data.is_secret', false)
        ->assertJsonPath('data.value', 'production');

    $this->postJson(route('api.v1.app.projects.environment-variables.store', $project), [
        'key' => 'API_KEY', 'value' => 'super-secret-value',
    ])
        ->assertCreated()
        ->assertJsonPath('data.is_secret', true)
        ->assertJsonMissingPath('data.value');
});

test('the list shows non-secret values and hides secret ones', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'PUBLIC_URL', 'encrypted_value' => 'https://a.test', 'is_secret' => false]);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'TOKEN', 'encrypted_value' => 'super-secret-value', 'is_secret' => true]);

    $response = $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.projects.environment-variables.index', $project))
        ->assertOk()
        ->assertDontSee('super-secret-value');

    $byKey = collect($response->json('data'))->keyBy('key');

    expect($byKey['PUBLIC_URL']['value'])->toBe('https://a.test')
        ->and($byKey['TOKEN'])->not->toHaveKey('value');
});

test('a value update tells the console a redeploy is needed', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $live = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'created_at' => now()->subHour(),
    ]);
    $project->update(['active_deployment_id' => $live->id]);
    $variable = EnvironmentVariable::factory()->for($project)->create(['key' => 'API_URL', 'encrypted_value' => 'old-value-1', 'is_secret' => false]);

    $this->actingAs($user, 'web')
        ->patchJson(route('api.v1.app.projects.environment-variables.update', [$project, $variable]), ['value' => 'new-value-2'])
        ->assertOk()
        ->assertJsonPath('data.value', 'new-value-2')
        ->assertJsonPath('data.redeploy_required', true);

    expect($variable->fresh()->encrypted_value)->toBe('new-value-2');

    $this->getJson("/api/v1/app/projects/{$project->id}")->assertJsonPath('data.redeploy_required', true);
});

test('flipping is_secret alone needs no redeploy', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $live = Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Succeeded, 'sequence' => 1]);
    $project->update(['active_deployment_id' => $live->id]);
    $variable = EnvironmentVariable::factory()->for($project)->create(['encrypted_value' => 'value-1', 'is_secret' => false]);

    $this->actingAs($user, 'web')
        ->patchJson(route('api.v1.app.projects.environment-variables.update', [$project, $variable]), ['is_secret' => true])
        ->assertOk()
        ->assertJsonPath('data.is_secret', true)
        ->assertJsonMissingPath('data.value')
        ->assertJsonPath('data.redeploy_required', false);
});

test('creating and deleting a variable also ask for a redeploy', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $live = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
        'created_at' => now()->subHour(),
    ]);
    $project->update(['active_deployment_id' => $live->id]);

    $this->actingAs($user, 'web');

    $id = $this->postJson(route('api.v1.app.projects.environment-variables.store', $project), [
        'key' => 'NEW_ONE', 'value' => 'value-1', 'is_secret' => false,
    ])->assertJsonPath('data.redeploy_required', true)->json('data.id');

    $project->update(['config_changed_at' => null]);

    $this->deleteJson(route('api.v1.app.projects.environment-variables.destroy', [$project, $id]))->assertNoContent();

    expect($project->fresh()->config_changed_at)->not->toBeNull();
});

test('updating a variable validates input and checks ownership', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $otherProject = Project::factory()->for($user)->create();
    $variable = EnvironmentVariable::factory()->for($project)->create(['encrypted_value' => 'value-1']);
    $url = route('api.v1.app.projects.environment-variables.update', [$project, $variable]);

    $this->patchJson($url, ['value' => 'x'])->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'web')
        ->patchJson($url, ['value' => 'hijacked'])
        ->assertForbidden();

    app('auth')->forgetGuards();

    $this->actingAs($user, 'web');

    $this->patchJson($url, ['value' => ''])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $this->patchJson($url, ['is_secret' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['is_secret']);

    // Scoped binding: the variable must belong to the project in the URL.
    $this->patchJson(route('api.v1.app.projects.environment-variables.update', [$otherProject, $variable]), ['value' => 'x'])
        ->assertNotFound();

    expect($variable->fresh()->encrypted_value)->toBe('value-1');
});

test('a public URL project cannot turn on auto_deploy', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web');

    $response = $this->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['auto_deploy']);

    expect($response->json('errors.auto_deploy.0'))->toContain('URL publik')
        ->and($project->fresh()->auto_deploy)->toBeFalse();

    $this->postJson('/api/v1/app/projects', [
        'name' => 'Public',
        'repository_url' => 'https://github.com/example/public',
        'branch' => 'main',
        'auto_deploy' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['auto_deploy']);

    // Turning it off is always fine.
    $this->patchJson("/api/v1/app/projects/{$project->id}", ['auto_deploy' => false])->assertOk();
});

test('public_url follows the configured scheme and port', function (string $scheme, ?string $port, string $expected): void {
    config()->set('sakala.project.url_scheme', $scheme);
    config()->set('sakala.project.url_port', $port);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['default_domain' => 'app.run.test']);

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertJsonPath('data.public_url', $expected);
})->with([
    'default' => ['https', null, 'https://app.run.test'],
    'local with port' => ['http', '8180', 'http://app.run.test:8180'],
    'empty port from env' => ['http', '', 'http://app.run.test'],
]);
