<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Deployment;
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

test('project quota returns a stable code and an Indonesian message', function (): void {
    config(['sakala.pilot_limits.max_projects_per_user' => 1]);
    $user = User::factory()->create(['role' => UserRole::User]);
    Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Project Dua',
            'repository_url' => 'https://github.com/example/project-dua',
            'branch' => 'main',
        ])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Kamu sudah mencapai batas 1 proyek selama fase pilot. Hapus proyek yang tidak dipakai untuk membuat proyek baru.',
            'code' => 'PROJECT_LIMIT_EXCEEDED',
            'limit' => 1,
            'current' => 1,
        ]);
});

test('the active deployment limit per project returns a stable code and an Indonesian message', function (): void {
    config(['sakala.pilot_limits.max_active_deployments_per_project' => 1]);
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);
    Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Building, 'sequence' => 1]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED')
        ->assertJsonPath('scope', 'project')
        ->assertJsonPath('message', 'Proyek ini sudah punya 1 deployment yang berjalan, dan itu batas selama fase pilot. Tunggu sampai selesai, lalu deploy lagi.');
});

test('the active deployment limit per user names the user scope in Indonesian', function (): void {
    config([
        'sakala.pilot_limits.max_active_deployments_per_project' => 5,
        'sakala.pilot_limits.max_active_deployments_per_user' => 1,
    ]);
    $user = User::factory()->create(['role' => UserRole::User]);
    $busy = Project::factory()->for($user)->create();
    Deployment::factory()->for($busy)->create(['status' => DeploymentStatus::Building, 'sequence' => 1]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'ACTIVE_DEPLOYMENT_LIMIT_EXCEEDED')
        ->assertJsonPath('scope', 'user')
        ->assertJsonPath('message', 'Kamu sudah punya 1 deployment yang berjalan, dan itu batas selama fase pilot. Tunggu salah satunya selesai, lalu deploy lagi.');
});

test('an oversized resource request returns a stable code and an Indonesian message', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", [
            'branch' => 'main',
            'resources' => ['memory_mb' => 1024],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'RESOURCE_LIMIT_EXCEEDED')
        ->assertJsonPath('message', 'Nilai memory_mb yang diminta (1024) tidak bisa dipakai. Selama fase pilot, nilainya harus lebih dari 0 dan paling besar 512.');
});

test('the owner of a suspended project learns why it cannot deploy', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create([
        'branch' => 'main',
        'status' => ProjectStatus::Suspended,
    ]);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertExactJson([
            'message' => 'Proyek ini sedang ditangguhkan oleh admin, jadi belum bisa di-deploy. Kirim email ke abuse@sakala.dev kalau kamu ingin mengajukan banding.',
            'code' => 'PROJECT_SUSPENDED',
        ]);

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(0);
});

test('an admin deploying a suspended project gets the same error contract', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $project = Project::factory()->for(User::factory())->create([
        'branch' => 'main',
        'status' => ProjectStatus::Suspended,
    ]);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_SUSPENDED');
});

test('another user learns nothing about a suspended project', function (): void {
    $project = Project::factory()->create([
        'branch' => 'main',
        'status' => ProjectStatus::Suspended,
    ]);

    $response = $this->actingAs(User::factory()->create(['role' => UserRole::User]), 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertForbidden();

    expect($response->json())->not->toHaveKey('code')
        ->and($response->json('message'))->not->toContain('ditangguhkan');
});

test('fields sent by the console carry a label instead of the raw column name', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    // `resources.cpu_millis` has no label before this change and would read
    // "Kolom resources.cpu_millis harus minimal 1."
    $response = $this->actingAs($user, 'web')->postJson(
        "/api/v1/app/projects/{$project->id}/deployments",
        ['branch' => 'main', 'resources' => ['cpu_millis' => 0]],
    )->assertUnprocessable();

    // The key itself contains a dot, so read it as an array rather than through
    // `json()` dot-notation, which would treat it as a nested path.
    expect($response->json('errors')['resources.cpu_millis'][0])
        ->toBe('Kolom CPU harus minimal 1.');
});

test('console validation errors are Indonesian and use the field label', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);

    $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', ['branch' => 'main', 'repository_url' => 'https://github.com/example/app'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Kolom nama wajib diisi.')
        ->assertJsonPath('message', 'Kolom nama wajib diisi.');
});

test('summarised validation errors are Indonesian too', function (): void {
    $this->postJson(route('api.v1.auth.login'), [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Kolom email wajib diisi. (dan 1 kesalahan lainnya)')
        ->assertJsonPath('errors.password.0', 'Kolom kata sandi wajib diisi.');
});

test('a repository URL from another provider gets an Indonesian explanation', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);

    $response = $this->actingAs($user, 'web')
        ->postJson('/api/v1/app/projects', [
            'name' => 'Project',
            'repository_url' => 'https://gitlab.com/example/app',
            'branch' => 'main',
        ])
        ->assertUnprocessable();

    expect($response->json('errors')['repository.url'][0])
        ->toBe('Saat ini Sakala hanya mendukung repository dari GitHub.');
});

test('a branch that differs from the project branch is explained in Indonesian', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);
    $project = Project::factory()->for($user)->create(['branch' => 'main']);

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'develop'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.branch.0', 'Branch yang dipilih berbeda dengan branch proyek. Pilih branch yang terdaftar di proyek ini.');
});

test('every framework validation rule has an Indonesian line', function (): void {
    $framework = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
    $indonesian = require lang_path('id/validation.php');

    expect(array_keys($indonesian))->toEqualCanonicalizing(array_keys($framework));

    foreach ($framework as $rule => $line) {
        if (is_array($line) && ! in_array($rule, ['custom', 'attributes'], true)) {
            expect(array_keys($indonesian[$rule]))->toEqualCanonicalizing(array_keys($line));
        }
    }
});
