<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00 UTC'));
    $this->admin = adminApiAdmin();
});

function adminApiFailedDeployment(Project $project, int $sequence, ?string $code, string $createdAt, array $attributes = []): Deployment
{
    return Deployment::factory()->for($project)->create([
        'requested_by' => $project->user_id,
        'sequence' => $sequence,
        'status' => DeploymentStatus::Failed,
        'failure_code' => $code,
        'created_at' => $createdAt,
        ...$attributes,
    ]);
}

test('admins list failed deployments across projects with category, code, search, and range filters', function (): void {
    $kue = Project::factory()->create(['name' => 'Toko Kue']);
    $blog = Project::factory()->create(['name' => 'Blog Kampus']);
    $gone = Project::factory()->create(['name' => 'Arsip Lama']);

    $build = adminApiFailedDeployment($kue, 1, 'runtime_build_failed', '2026-11-10 03:00:00', [
        'failure_detail' => 'npm run build exited with 1', 'failure_stage' => DeploymentStatus::Building,
        'started_at' => '2026-11-10 03:00:10', 'finished_at' => '2026-11-10 03:04:22',
    ]);
    $health = adminApiFailedDeployment($blog, 1, 'runtime_health_check_failed', '2026-11-09 03:00:00');
    $unknown = adminApiFailedDeployment($blog, 2, null, '2026-11-08 03:00:00');
    $odd = adminApiFailedDeployment($gone, 1, 'something_new', '2026-11-07 03:00:00');
    $gone->delete();
    adminApiFailedDeployment($kue, 2, 'runtime_build_failed', '2026-09-01 03:00:00'); // outside 30 days
    Deployment::factory()->for($kue)->create(['sequence' => 3, 'status' => DeploymentStatus::Succeeded, 'created_at' => '2026-11-10 04:00:00']);

    $response = $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/deployments/failures')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$build->id, $health->id, $unknown->id, $odd->id]);
    $response
        ->assertJsonPath('data.0.project.name', 'Toko Kue')
        ->assertJsonPath('data.0.failure.code', 'runtime_build_failed')
        ->assertJsonPath('data.0.failure.category', 'build')
        ->assertJsonPath('data.0.failure.user_fixable', true)
        ->assertJsonPath('data.0.failure.detail', 'npm run build exited with 1')
        ->assertJsonPath('data.0.failure.stage', 'building')
        ->assertJsonPath('data.0.duration_seconds', 252)
        ->assertJsonPath('data.2.failure.code', null)
        ->assertJsonPath('data.2.failure.category', 'unknown')
        ->assertJsonPath('data.3.project.deleted', true);

    $ids = fn (string $query): array => array_column($this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/deployments/failures?'.$query)->assertOk()->json('data'), 'id');

    expect($ids('category=build'))->toBe([$build->id])
        ->and($ids('category=unknown'))->toBe([$unknown->id, $odd->id])
        ->and($ids('category=resource'))->toBe([])
        ->and($ids('code=runtime_health_check_failed'))->toBe([$health->id])
        ->and($ids('search=kampus'))->toBe([$health->id, $unknown->id])
        ->and($ids('search=arsip'))->toBe([$odd->id])
        ->and($ids('range=7d'))->toBe([$build->id, $health->id, $unknown->id, $odd->id])
        ->and($ids('range=custom&from=2026-11-08&to=2026-11-09'))->toBe([$health->id, $unknown->id])
        ->and($ids('range=all'))->toHaveCount(5);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/deployments/failures?category=build_timeout')->assertUnprocessable();
});

test('the failure summary counts categories and codes with the previous window', function (): void {
    $project = Project::factory()->create();
    adminApiFailedDeployment($project, 1, 'runtime_build_failed', '2026-11-10 03:00:00');
    adminApiFailedDeployment($project, 2, 'runtime_root_directory_invalid', '2026-11-09 03:00:00');
    adminApiFailedDeployment($project, 3, 'runtime_timeout', '2026-11-08 03:00:00');
    adminApiFailedDeployment($project, 4, 'runtime_build_failed', '2026-11-01 03:00:00'); // previous 7 days

    $response = $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/deployments/failure-summary?range=7d')->assertOk();

    $response
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.previous_total', 1)
        ->assertJsonPath('data.categories.0', ['category' => 'build', 'count' => 2, 'previous_count' => 1])
        ->assertJsonPath('data.categories.1', ['category' => 'timeout', 'count' => 1, 'previous_count' => 0])
        ->assertJsonPath('data.codes.0', ['code' => 'runtime_build_failed', 'category' => 'build', 'count' => 1]);

    expect($response->json('data.categories'))->toHaveCount(10);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/deployments/failure-summary?range=all')
        ->assertJsonPath('data.total', 4)
        ->assertJsonPath('data.previous_total', null)
        ->assertJsonPath('data.categories.0.previous_count', null);
});

test('admins read any deployment detail, events, and logs, with secrets redacted and no payload', function (): void {
    $owner = User::factory()->create(['name' => 'Pemilik']);
    $project = Project::factory()->for($owner)->create();
    $node = AgentNode::factory()->create(['name' => 'agent-jkt-01']);
    $deployment = adminApiFailedDeployment($project, 1, 'runtime_build_failed', '2026-11-10 03:00:00', ['agent_node_id' => $node->id]);
    DeploymentEvent::factory()->for($deployment)->create(['sequence' => 1, 'message' => 'Building image']);
    DeploymentLog::factory()->for($deployment)->create(['sequence' => 1, 'message' => 'TOKEN=[REDACTED]']);
    DeploymentLog::factory()->for($deployment)->create(['sequence' => 2, 'message' => 'npm ERR! build failed']);

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/deployments/{$deployment->id}")
        ->assertOk()
        ->assertJsonPath('data.deployment.id', $deployment->id)
        ->assertJsonPath('data.deployment.failure.category', 'build')
        ->assertJsonPath('data.project.id', $project->id)
        ->assertJsonPath('data.requested_by.name', 'Pemilik')
        ->assertJsonPath('data.agent_node.name', 'agent-jkt-01')
        ->assertJsonMissingPath('data.deployment.payload');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/deployments/{$deployment->id}/events")
        ->assertOk()
        ->assertJsonPath('data.0.message', 'Building image');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/deployments/{$deployment->id}/logs?order=desc&per_page=200")
        ->assertOk()
        ->assertJsonPath('data.0.message', 'npm ERR! build failed')
        ->assertJsonPath('data.1.message', 'TOKEN=[REDACTED]');

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/deployments/{$deployment->id}/logs?per_page=500")->assertUnprocessable();
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/deployments/0193b6a0-0000-7000-8000-000000000000')->assertNotFound();
});
