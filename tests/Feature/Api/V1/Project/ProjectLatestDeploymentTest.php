<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const LATEST_DEPLOYMENT_FIELDS = [
    'id', 'sequence', 'status', 'branch', 'commit_sha', 'commit_message', 'created_at', 'finished_at',
];

/** @param array<string, mixed> $attributes */
function deploymentOf(Project $project, int $sequence, array $attributes = []): Deployment
{
    return Deployment::factory()->for($project)->create(['sequence' => $sequence, ...$attributes]);
}

test('the list gives each project its newest deployment and its public url', function (): void {
    config()->set('sakala.project.url_scheme', 'http');
    config()->set('sakala.project.url_port', '8180');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['default_domain' => 'app.run.test']);

    deploymentOf($project, 1, ['status' => DeploymentStatus::Succeeded]);
    $finishedAt = CarbonImmutable::parse('2026-11-05 10:00:00');
    $latest = deploymentOf($project, 2, [
        'status' => DeploymentStatus::Failed,
        'branch' => 'main',
        'commit_sha' => str_repeat('a', 40),
        'commit_message' => 'fix: tombol login',
        'finished_at' => $finishedAt,
    ]);

    $response = $this->actingAs($user, 'web')->getJson('/api/v1/app/projects')->assertOk();

    expect($response->json('data.0.public_url'))->toBe('http://app.run.test:8180')
        ->and($response->json('data.0.latest_deployment'))->toBe([
            'id' => $latest->id,
            'sequence' => 2,
            'status' => 'failed',
            'branch' => 'main',
            'commit_sha' => str_repeat('a', 40),
            'commit_message' => 'fix: tombol login',
            'created_at' => $latest->created_at?->toAtomString(),
            'finished_at' => $finishedAt->toAtomString(),
        ]);
});

test('newest means the highest sequence, even when timestamps disagree', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    deploymentOf($project, 1, ['created_at' => now()]);
    $latest = deploymentOf($project, 2, ['created_at' => now()->subDay()]);

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/app/projects')
        ->assertJsonPath('data.0.latest_deployment.id', $latest->id)
        ->assertJsonPath('data.0.latest_deployment.sequence', 2);
});

test('a project without deployments has null, not another project\'s deployment', function (): void {
    $user = User::factory()->create();
    $empty = Project::factory()->for($user)->create();
    $deployed = Project::factory()->for($user)->create();
    $deployment = deploymentOf($deployed, 1);

    $otherProject = Project::factory()->for(User::factory())->create();
    deploymentOf($otherProject, 7);

    $byId = collect($this->actingAs($user, 'web')->getJson('/api/v1/app/projects')->assertOk()->json('data'))
        ->keyBy('id');

    expect($byId)->toHaveCount(2)
        ->and($byId[$empty->id]['latest_deployment'])->toBeNull()
        ->and($byId[$deployed->id]['latest_deployment']['id'])->toBe($deployment->id);
});

test('show carries the same fields as the list', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.latest_deployment', null);

    deploymentOf($project, 1);
    $latest = deploymentOf($project, 2);

    $shown = $this->getJson("/api/v1/app/projects/{$project->id}")->assertOk()->json('data.latest_deployment');
    $listed = $this->getJson('/api/v1/app/projects')->assertOk()->json('data.0.latest_deployment');

    expect($shown)->toBe($listed)
        ->and(array_keys($shown))->toBe(LATEST_DEPLOYMENT_FIELDS)
        ->and($shown['id'])->toBe($latest->id);
});

test('another user cannot read a latest deployment through show', function (): void {
    $project = Project::factory()->for(User::factory())->create();
    deploymentOf($project, 1);

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertForbidden()
        ->assertJsonMissingPath('data.latest_deployment');
});

test('commit text with markup comes back as written, for the console to show as text', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $text = '<img src=x onerror=alert(1)> & "quoted"';
    deploymentOf($project, 1, ['commit_message' => $text]);

    $this->actingAs($user, 'web')
        ->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('data.0.latest_deployment.commit_message', $text);
});

test('the list reads deployments once, however many projects it shows', function (): void {
    $user = User::factory()->create();

    $measure = function () use ($user): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user, 'web')->getJson('/api/v1/app/projects?per_page=6')->assertOk();

        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    };

    $addProject = function () use ($user): void {
        $project = Project::factory()->for($user)->create();
        deploymentOf($project, 1);
        deploymentOf($project, 2);
    };

    $addProject();
    $withOne = $measure();

    foreach (range(1, 5) as $ignored) {
        $addProject();
    }
    $withSix = $measure();

    expect(count($withSix))->toBe(count($withOne))
        ->and(array_filter($withSix, static fn (string $query): bool => str_contains($query, '"deployments"')))
        ->toHaveCount(1);
});
