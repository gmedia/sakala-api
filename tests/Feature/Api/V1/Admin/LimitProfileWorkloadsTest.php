<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\User;
use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** The ADR-022 fields and their option-A defaults. */
const WORKLOAD_LIMIT_DEFAULTS = [
    'max_worker_containers' => 1,
    'max_schedules' => 2,
    'min_schedule_interval_minutes' => 15,
    'max_concurrent_job_runs' => 1,
];

function workloadProfileFields(array $overrides = []): array
{
    return [
        'name' => 'Worker',
        'max_projects' => 5,
        'max_active_containers' => 10,
        'max_active_deployments' => 2,
        'max_memory_mb' => 512,
        'max_cpu_millis' => 1000,
        'reason' => 'Uji worker dan jadwal',
        ...$overrides,
    ];
}

test('admins set, change, and clear the worker and schedule limits of a profile, with an audit trail', function (): void {
    $admin = adminApiAdmin();
    $values = ['max_worker_containers' => 4, 'max_schedules' => 10, 'min_schedule_interval_minutes' => 1, 'max_concurrent_job_runs' => 2];

    $id = $this->actingAs($admin, 'web')
        ->postJson('/api/v1/admin/limit-profiles', workloadProfileFields(['name' => 'GMEDIA', ...$values]))
        ->assertCreated()
        ->assertJsonPath('data.max_worker_containers', 4)
        ->assertJsonPath('data.max_schedules', 10)
        ->assertJsonPath('data.min_schedule_interval_minutes', 1)
        ->assertJsonPath('data.max_concurrent_job_runs', 2)
        ->json('data.id');

    $this->patchJson("/api/v1/admin/limit-profiles/{$id}", ['max_schedules' => null, 'reason' => 'Ikut global'])
        ->assertOk()
        ->assertJsonPath('data.max_schedules', null);

    expect(AuditEvent::query()->where('action', 'limit_profile.created')->sole()->metadata['values'])->toMatchArray($values)
        ->and(AuditEvent::query()->where('action', 'limit_profile.updated')->sole()->metadata)->toMatchArray(['from' => ['max_schedules' => 10], 'to' => ['max_schedules' => null]]);
});

test('the worker and schedule limits are bounded', function (array $fields, string $error): void {
    $this->actingAs(adminApiAdmin(), 'web')
        ->postJson('/api/v1/admin/limit-profiles', workloadProfileFields($fields))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);
})->with([
    'negative workers' => [['max_worker_containers' => -1], 'max_worker_containers'],
    'workers above the ceiling' => [['max_worker_containers' => LimitProfile::MAX_WORKER_CONTAINERS_CEILING + 1], 'max_worker_containers'],
    'schedules above the ceiling' => [['max_schedules' => LimitProfile::MAX_SCHEDULES_CEILING + 1], 'max_schedules'],
    'interval of 0' => [['min_schedule_interval_minutes' => 0], 'min_schedule_interval_minutes'],
    'interval above a day' => [['min_schedule_interval_minutes' => LimitProfile::MIN_SCHEDULE_INTERVAL_CEILING + 1], 'min_schedule_interval_minutes'],
    'negative runs' => [['max_concurrent_job_runs' => -1], 'max_concurrent_job_runs'],
]);

test('option A is the global default; null follows the global setting and empty env values fall back to it', function (): void {
    $service = app(PilotRuntimeLimitService::class);
    $profile = LimitProfile::factory()->create();

    $limits = $service->scheduleLimitsFor($profile);
    expect([
        'max_worker_containers' => $limits->maxWorkerContainers,
        'max_schedules' => $limits->maxSchedules,
        'min_schedule_interval_minutes' => $limits->minScheduleIntervalMinutes,
        'max_concurrent_job_runs' => $limits->maxConcurrentJobRuns,
    ])->toBe(WORKLOAD_LIMIT_DEFAULTS);

    config(['sakala.workers.default_max_containers' => '', 'sakala.schedules.default_max_count' => '5', 'sakala.schedules.default_min_interval_minutes' => null]);
    $limits = $service->scheduleLimitsFor(null);

    expect($limits->maxWorkerContainers)->toBe(1)
        ->and($limits->maxSchedules)->toBe(5)
        ->and($limits->minScheduleIntervalMinutes)->toBe(15)
        ->and($service->scheduleLimitsFor(LimitProfile::factory()->create(['max_worker_containers' => 0]))->maxWorkerContainers)->toBe(0);
});

test('the console and admin quota views show the worker and schedule limits and usage', function (): void {
    enableWorkersAndSchedules();
    ['team' => $team, 'project' => $project] = workerProject(profile: ['max_worker_containers' => 3, 'max_schedules' => 4]);
    addProcess($project);
    deployLive($project, $team['owner']);
    scheduleFor($project);
    scheduleFor($project, ['enabled' => false]);

    $this->actingAs($team['member'])
        ->getJson("/api/v1/app/pilot-limits?workspace_id={$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.quotas.max_worker_containers', 3)
        ->assertJsonPath('data.quotas.current_worker_containers_count', 1)
        ->assertJsonPath('data.quotas.max_schedules', 4)
        ->assertJsonPath('data.quotas.current_schedules_count', 1)
        ->assertJsonPath('data.quotas.min_schedule_interval_minutes', 15)
        ->assertJsonPath('data.quotas.max_concurrent_job_runs', 1)
        ->assertJsonPath('data.quotas.current_active_containers_count', 2)
        ->assertJsonPath('data.workers.enabled', true)
        ->assertJsonPath('data.schedules.enabled', true)
        ->assertJsonPath('data.schedules.default_timezone', 'Asia/Jakarta')
        ->assertJsonPath('data.schedules.max_timeout_seconds', 600);

    $this->actingAs(adminApiAdmin(), 'web')
        ->getJson("/api/v1/admin/workspaces/{$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.quota.limits.max_worker_containers', 3)
        ->assertJsonPath('data.quota.usage.worker_containers', 1)
        ->assertJsonPath('data.quota.usage.schedules', 1);
});

test('a personal workspace without a profile gets option A', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/app/pilot-limits')
        ->assertOk()
        ->assertJsonPath('data.quotas.max_worker_containers', 1)
        ->assertJsonPath('data.quotas.max_schedules', 2)
        ->assertJsonPath('data.quotas.min_schedule_interval_minutes', 15)
        ->assertJsonPath('data.quotas.max_concurrent_job_runs', 1)
        ->assertJsonPath('data.workers.enabled', false)
        ->assertJsonPath('data.schedules.enabled', false);
});
