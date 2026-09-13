<?php

declare(strict_types=1);

use App\Enums\DeploymentFailureCategory;
use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(
        CarbonImmutable::parse('2026-09-12 12:00:00 UTC'),
    );
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('admin can view pilot validation metrics', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/metrics/pilot-validation');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'from',
                'to',
                'activated_users',
                'successful_deployments',
                'unique_deployers',
                'repeat_deployers',
                'failure_categories',
                'pilot_feedback_count',
            ],
        ]);
});

test('non admin cannot view pilot validation metrics', function (): void {
    $user = User::factory()->create([
        'role' => UserRole::User,
    ]);

    $this
        ->actingAs($user, 'web')
        ->getJson('/api/v1/admin/metrics/pilot-validation')
        ->assertForbidden();
});

test('pilot validation metrics are calculated correctly', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $userA = User::factory()->create([
        'onboarding_completed_at' => '2026-09-02 10:00:00',
    ]);

    $userB = User::factory()->create([
        'onboarding_completed_at' => '2026-09-03 10:00:00',
    ]);

    $userC = User::factory()->create([
        'onboarding_completed_at' => null,
    ]);

    $projectA = Project::factory()->create([
        'user_id' => $userA->id,
    ]);

    $projectB = Project::factory()->create([
        'user_id' => $userB->id,
    ]);

    $projectC = Project::factory()->create([
        'user_id' => $userC->id,
    ]);

    // User A: 1 successful deployment.
    Deployment::factory()->create([
        'project_id' => $projectA->id,
        'requested_by' => $userA->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-04 10:00:00',
    ]);

    // User B: 1 successful + 1 failed deployment.
    Deployment::factory()->create([
        'project_id' => $projectB->id,
        'requested_by' => $userB->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-05 10:00:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $projectB->id,
        'requested_by' => $userB->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'failure_summary' => 'internal build failure',
        'created_at' => '2026-09-06 10:00:00',
    ]);

    // User C: failed deployment.
    Deployment::factory()->create([
        'project_id' => $projectC->id,
        'requested_by' => $userC->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_timeout',
        'failure_summary' => 'internal timeout details',
        'created_at' => '2026-09-07 10:00:00',
    ]);

    // User C: another failure category.
    Deployment::factory()->create([
        'project_id' => $projectC->id,
        'requested_by' => $userC->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_capacity_exceeded',
        'failure_summary' => 'internal resource details',
        'created_at' => '2026-09-08 10:00:00',
    ]);

    Feedback::factory()->count(2)->create([
        'created_at' => '2026-09-09 10:00:00',
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/metrics/pilot-validation');

    $response
        ->assertOk()
        ->assertJsonPath('data.activated_users', 2)
        ->assertJsonPath('data.successful_deployments', 2)
        ->assertJsonPath('data.unique_deployers', 3)
        ->assertJsonPath('data.repeat_deployers', 2)
        ->assertJsonPath('data.failure_categories.'.DeploymentFailureCategory::Build->value, 1)
        ->assertJsonPath('data.failure_categories.'.DeploymentFailureCategory::Timeout->value, 1)
        ->assertJsonPath('data.failure_categories.'.DeploymentFailureCategory::Resource->value, 1)
        ->assertJsonPath('data.pilot_feedback_count', 2);
});

test('empty dataset returns zero metrics', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/metrics/pilot-validation');

    $response
        ->assertOk()
        ->assertJsonPath('data.activated_users', 0)
        ->assertJsonPath('data.successful_deployments', 0)
        ->assertJsonPath('data.unique_deployers', 0)
        ->assertJsonPath('data.repeat_deployers', 0)
        ->assertJsonPath('data.pilot_feedback_count', 0);

    foreach (DeploymentFailureCategory::cases() as $category) {
        $response->assertJsonPath(
            'data.failure_categories.'.$category->value,
            0,
        );
    }
});

test('custom date range is respected', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $user = User::factory()->create([
        'onboarding_completed_at' => '2026-09-05 10:00:00',
    ]);

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-05 12:00:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-10 12:00:00',
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson(
            '/api/v1/admin/metrics/pilot-validation?'.
            'from=2026-09-01T00:00:00Z&'.
            'to=2026-09-07T00:00:00Z',
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.activated_users', 1)
        ->assertJsonPath('data.successful_deployments', 1)
        ->assertJsonPath('data.unique_deployers', 1)
        ->assertJsonPath('data.repeat_deployers', 0);
});

test('metric date range uses an exclusive to boundary', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $user = User::factory()->create([
        'onboarding_completed_at' => '2026-09-01 00:00:00',
    ]);

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-05 00:00:00',
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-06 00:00:00',
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson(
            '/api/v1/admin/metrics/pilot-validation?'.
            'from=2026-09-01T00:00:00Z&'.
            'to=2026-09-06T00:00:00Z',
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.successful_deployments', 1);
});

test('metrics do not expose raw failure details', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Failed,
        'failure_code' => 'runtime_build_failed',
        'failure_summary' => 'SUPER_SECRET_INTERNAL_FAILURE',
        'created_at' => '2026-09-05 10:00:00',
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/metrics/pilot-validation');

    $response
        ->assertOk()
        ->assertJsonMissing([
            'failure_code' => 'runtime_build_failed',
        ])
        ->assertJsonMissing([
            'failure_summary' => 'SUPER_SECRET_INTERNAL_FAILURE',
        ])
        ->assertJsonMissingPath('data.failure_code')
        ->assertJsonMissingPath('data.failure_summary');
});

test('default date range uses current month until now', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $user = User::factory()->create([
        'onboarding_completed_at' => '2026-09-05 10:00:00',
    ]);

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-09-05 12:00:00',
    ]);

    // Outside current month.
    Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $user->id,
        'status' => DeploymentStatus::Succeeded,
        'created_at' => '2026-08-31 23:59:59',
    ]);

    $response = $this
        ->actingAs($admin, 'web')
        ->getJson('/api/v1/admin/metrics/pilot-validation');

    $response
        ->assertOk()
        ->assertJsonPath(
            'data.from',
            '2026-09-01T00:00:00+00:00',
        )
        ->assertJsonPath(
            'data.to',
            '2026-09-12T12:00:00+00:00',
        )
        ->assertJsonPath('data.activated_users', 1)
        ->assertJsonPath('data.successful_deployments', 1);
});
