<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\OnboardingSource;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // 17:00 WIB on 11 November; "7d" is 5 through 11 November WIB.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00 UTC'));
    $this->admin = adminApiAdmin(['created_at' => '2026-01-01 00:00:00']);
});

/** A user, a project of theirs, and deployments in the given statuses, all created at $at (UTC). */
function adminApiSeedActivity(string $at, array $statuses, ?OnboardingSource $source = null): Project
{
    $user = User::factory()->create(['created_at' => $at, 'onboarding_source' => $source]);
    $project = Project::factory()->for($user)->create(['created_at' => $at]);

    foreach ($statuses as $index => $status) {
        [$status, $code] = is_array($status) ? $status : [$status, null];
        Deployment::factory()->for($project)->create([
            'requested_by' => $user->id,
            'sequence' => $index + 1,
            'status' => $status,
            'failure_code' => $code,
            'created_at' => $at,
        ]);
    }

    return $project;
}

test('the overview counts users, projects, and deployments in the range and before it', function (): void {
    // In range: 6 and 11 November WIB.
    $serving = adminApiSeedActivity('2026-11-06 03:00:00', [
        DeploymentStatus::Succeeded,
        [DeploymentStatus::Failed, 'runtime_build_failed'],
        [DeploymentStatus::Failed, 'runtime_build_failed'],
    ], OnboardingSource::Campus);
    $serving->update(['active_deployment_id' => $serving->deployments()->where('sequence', 1)->value('id')]);

    adminApiSeedActivity('2026-11-11 09:00:00', [
        [DeploymentStatus::Failed, 'runtime_health_check_failed'],
        [DeploymentStatus::Failed, null],
        DeploymentStatus::Cancelled,
        DeploymentStatus::Building,
    ], OnboardingSource::Campus);

    // 18:00 UTC on 10 November is 01:00 WIB on 11 November.
    adminApiSeedActivity('2026-11-10 18:00:00', [], OnboardingSource::Github);

    // Previous window (29 October to 4 November WIB): one user, one success, one failure.
    adminApiSeedActivity('2026-11-01 05:00:00', [DeploymentStatus::Succeeded, [DeploymentStatus::Failed, 'command_expired']]);

    // Outside both windows.
    adminApiSeedActivity('2026-09-01 05:00:00', [DeploymentStatus::Succeeded]);

    Feedback::factory()->create(['user_id' => $this->admin->id, 'created_at' => '2026-11-07 00:00:00']);

    $response = $this->actingAs($this->admin, 'web')
        ->getJson('/api/v1/admin/overview?range=7d')
        ->assertOk();

    $response
        ->assertJsonPath('data.range.key', '7d')
        ->assertJsonPath('data.range.from', '2026-11-04T17:00:00+00:00')
        ->assertJsonPath('data.range.to', '2026-11-11T17:00:00+00:00')
        ->assertJsonPath('data.range.timezone', 'Asia/Jakarta')
        ->assertJsonPath('data.totals.users', 6)
        ->assertJsonPath('data.totals.projects', 5)
        ->assertJsonPath('data.totals.active_projects', 1)
        ->assertJsonPath('data.period.new_users', 3)
        ->assertJsonPath('data.period.new_projects', 3)
        ->assertJsonPath('data.period.deployments', [
            'succeeded' => 1,
            'failed' => 4,
            'cancelled' => 1,
            'in_progress' => 1,
            'total' => 7,
        ])
        ->assertJsonPath('data.previous_period.from', '2026-10-28T17:00:00+00:00')
        ->assertJsonPath('data.previous_period.new_users', 1)
        ->assertJsonPath('data.previous_period.deployments.succeeded', 1)
        ->assertJsonPath('data.previous_period.deployments.failed', 1)
        ->assertJsonPath('data.pilot.unique_deployers', 2)
        ->assertJsonPath('data.pilot.repeat_deployers', 2)
        ->assertJsonPath('data.pilot.feedback_count', 1);

    expect($response->json('data.user_growth'))->toBe([
        ['date' => '2026-11-05', 'count' => 0],
        ['date' => '2026-11-06', 'count' => 1],
        ['date' => '2026-11-07', 'count' => 0],
        ['date' => '2026-11-08', 'count' => 0],
        ['date' => '2026-11-09', 'count' => 0],
        ['date' => '2026-11-10', 'count' => 0],
        ['date' => '2026-11-11', 'count' => 2],
    ]);

    expect(array_slice($response->json('data.failure_categories'), 0, 3))->toBe([
        ['category' => 'build', 'count' => 2],
        ['category' => 'health', 'count' => 1],
        ['category' => 'unknown', 'count' => 1],
    ])->and(collect($response->json('data.failure_categories'))->sum('count'))->toBe(4);

    expect($response->json('data.top_failure_codes'))->toBe([
        ['code' => 'runtime_build_failed', 'category' => 'build', 'count' => 2],
        ['code' => null, 'category' => 'unknown', 'count' => 1],
        ['code' => 'runtime_health_check_failed', 'category' => 'health', 'count' => 1],
    ]);

    $sources = collect($response->json('data.new_user_sources'));
    expect($sources->first())->toBe(['source' => 'campus', 'count' => 2])
        ->and($sources->firstWhere('source', 'github'))->toBe(['source' => 'github', 'count' => 1])
        ->and($sources->last())->toBe(['source' => null, 'count' => 0])
        ->and($sources)->toHaveCount(count(OnboardingSource::cases()) + 1);
});

test('a custom range covers whole WIB days from the first through the last date', function (): void {
    adminApiSeedActivity('2026-10-31 16:59:59', []); // 23:59:59 WIB on 31 October
    adminApiSeedActivity('2026-10-31 17:00:00', []); // 00:00 WIB on 1 November
    adminApiSeedActivity('2026-11-02 16:59:59', []); // last second of 2 November WIB
    adminApiSeedActivity('2026-11-02 17:00:00', []); // 3 November WIB

    $this->actingAs($this->admin, 'web')
        ->getJson('/api/v1/admin/overview?range=custom&from=2026-11-01&to=2026-11-02')
        ->assertOk()
        ->assertJsonPath('data.range.from', '2026-10-31T17:00:00+00:00')
        ->assertJsonPath('data.range.to', '2026-11-02T17:00:00+00:00')
        ->assertJsonPath('data.period.new_users', 2)
        ->assertJsonPath('data.user_growth', [
            ['date' => '2026-11-01', 'count' => 1],
            ['date' => '2026-11-02', 'count' => 1],
        ]);
});

test('the overview defaults to the last 30 days', function (): void {
    $response = $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/overview')->assertOk();

    expect($response->json('data.range.key'))->toBe('30d')
        ->and($response->json('data.user_growth'))->toHaveCount(30);
});

test('the overview rejects invalid ranges', function (string $query, string $field): void {
    $this->actingAs($this->admin, 'web')
        ->getJson('/api/v1/admin/overview?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown preset' => ['range=1y', 'range'],
    'all is unbounded' => ['range=all', 'range'],
    'custom without dates' => ['range=custom', 'from'],
    'end before start' => ['range=custom&from=2026-11-05&to=2026-11-01', 'to'],
    'longer than a year' => ['range=custom&from=2025-01-01&to=2026-11-01', 'to'],
    'dates without custom' => ['range=7d&from=2026-11-01', 'from'],
    'not a date' => ['range=custom&from=yesterday&to=2026-11-01', 'from'],
]);

test('the overview is cached for a minute', function (): void {
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/overview?range=7d')->assertJsonPath('data.totals.users', 1);

    User::factory()->create();

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/overview?range=7d')->assertJsonPath('data.totals.users', 1);

    $this->travel(61)->seconds();

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/overview?range=7d')->assertJsonPath('data.totals.users', 2);
});
