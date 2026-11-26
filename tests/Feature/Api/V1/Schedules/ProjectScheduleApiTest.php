<?php

declare(strict_types=1);

use App\Enums\SchedulePauseReason;
use App\Models\AuditEvent;
use App\Models\ProjectSchedule;
use App\Support\Project\WorkloadAudit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableWorkersAndSchedules();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:07:30', 'UTC'));
});

function scheduleUrl(string $projectId, ?string $scheduleId = null): string
{
    return "/api/v1/app/projects/{$projectId}/schedules".($scheduleId === null ? '' : "/{$scheduleId}");
}

test('a member creates a schedule read in WIB, stored and answered in UTC, and audited', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();

    $this->actingAs($team['member'])->postJson(scheduleUrl($project->id), [
        'name' => 'Laporan harian',
        'command' => 'php artisan report:daily',
        'cron_expression' => '30  2 * * *',
    ])
        ->assertCreated()
        ->assertJsonPath('data.cron_expression', '30 2 * * *')
        ->assertJsonPath('data.timezone', 'Asia/Jakarta')
        ->assertJsonPath('data.timeout_seconds', 300)
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.health', 'healthy')
        // 02.30 WIB on 25 November is 19.30 UTC on 24 November.
        ->assertJsonPath('data.next_run_at', '2026-11-24T19:30:00Z')
        ->assertJsonPath('data.latest_run', null);

    expect(AuditEvent::query()->where('action', WorkloadAudit::SCHEDULE_CREATED)->sole()->metadata)
        ->toMatchArray(['name' => 'Laporan harian', 'cron_expression' => '30 2 * * *', 'timezone' => 'Asia/Jakarta']);

    $this->actingAs($team['viewer'])->getJson(scheduleUrl($project->id))->assertOk()->assertJsonCount(1, 'data');
});

test('the expression, timezone, timeout, and command are validated', function (array $body, string $field): void {
    ['team' => $team, 'project' => $project] = workerProject();

    $this->actingAs($team['owner'])->postJson(scheduleUrl($project->id), [
        'name' => 'cleanup',
        'command' => 'php artisan cleanup',
        'cron_expression' => '0 * * * *',
        ...$body,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'four fields' => [['cron_expression' => '0 * * *'], 'cron_expression'],
    'a macro' => [['cron_expression' => '@daily'], 'cron_expression'],
    'nonsense' => [['cron_expression' => '61 * * * *'], 'cron_expression'],
    'every five minutes under the free minimum of 15' => [['cron_expression' => '*/5 * * * *'], 'cron_expression'],
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'timeout above 600' => [['timeout_seconds' => 601], 'timeout_seconds'],
    'timeout of 0' => [['timeout_seconds' => 0], 'timeout_seconds'],
    'multi-line command' => [['command' => "a\nb"], 'command'],
]);

test('a limit profile can allow a shorter interval', function (): void {
    ['team' => $team, 'project' => $project] = workerProject(profile: ['min_schedule_interval_minutes' => 5]);

    $this->actingAs($team['owner'])->postJson(scheduleUrl($project->id), [
        'name' => 'sync', 'command' => 'php artisan sync', 'cron_expression' => '*/5 * * * *',
    ])->assertCreated()->assertJsonPath('data.next_run_at', '2026-11-24T10:10:00Z');
});

test('enabled schedules stay within max_schedules; disabled ones do not count', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $body = ['command' => 'php artisan report', 'cron_expression' => '0 * * * *'];

    $this->actingAs($team['owner'])->postJson(scheduleUrl($project->id), ['name' => 'a', ...$body])->assertCreated();
    $this->actingAs($team['owner'])->postJson(scheduleUrl($project->id), ['name' => 'b', ...$body])->assertCreated();
    $this->actingAs($team['owner'])->postJson(scheduleUrl($project->id), ['name' => 'c', ...$body])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SCHEDULE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 2);

    $disabled = $this->actingAs($team['owner'])->postJson(scheduleUrl($project->id), ['name' => 'c', 'enabled' => false, ...$body])
        ->assertCreated()
        ->assertJsonPath('data.next_run_at', null)
        ->json('data.id');

    $this->actingAs($team['owner'])->patchJson(scheduleUrl($project->id, $disabled), ['enabled' => true])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SCHEDULE_LIMIT_EXCEEDED');
});

test('enabling a paused schedule clears the pause and computes the next slot from now', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $schedule = scheduleFor($project, [
        'enabled' => false,
        'paused_at' => now()->subDay(),
        'pause_reason' => SchedulePauseReason::ConsecutiveFailures,
        'consecutive_failures' => 10,
        'health' => 'failing',
    ]);

    $this->actingAs($team['member'])->patchJson(scheduleUrl($project->id, $schedule->id), ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.paused_at', null)
        ->assertJsonPath('data.pause_reason', null)
        ->assertJsonPath('data.consecutive_failures', 0)
        ->assertJsonPath('data.health', 'healthy')
        ->assertJsonPath('data.next_run_at', '2026-11-24T11:00:00Z');

    expect(AuditEvent::query()->where('action', WorkloadAudit::SCHEDULE_UPDATED)->sole()->metadata['to'])->toHaveKey('enabled');
});

test('viewers read schedules but cannot change them', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $schedule = scheduleFor($project);

    $this->actingAs($team['viewer'])->getJson(scheduleUrl($project->id, $schedule->id))->assertOk();
    $this->actingAs($team['viewer'])->patchJson(scheduleUrl($project->id, $schedule->id), ['enabled' => false])->assertForbidden();
    $this->actingAs($team['viewer'])->deleteJson(scheduleUrl($project->id, $schedule->id))->assertForbidden();
    $this->actingAs($team['outsider'])->getJson(scheduleUrl($project->id))->assertForbidden();
});

test('deleting a schedule is audited and removes its history', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $schedule = scheduleFor($project);

    $this->actingAs($team['owner'])->deleteJson(scheduleUrl($project->id, $schedule->id))->assertNoContent();

    expect(ProjectSchedule::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', WorkloadAudit::SCHEDULE_DELETED)->exists())->toBeTrue();
});
