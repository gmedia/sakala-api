<?php

declare(strict_types=1);

use App\Actions\Schedule\DispatchSchedulesAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\ScheduleRunReason;
use App\Enums\ScheduleRunStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableWorkersAndSchedules();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:15:20', 'UTC'));
});

/** @return array{project: Project, node: AgentNode, live: Deployment, schedule: ProjectSchedule, team: array<string, mixed>} */
function dueSchedule(array $capabilities = WORKER_SCHEDULE_CAPABILITIES, array $profile = [], array $schedule = []): array
{
    ['team' => $team, 'project' => $project, 'node' => $node] = workerProject($capabilities, $profile);
    $live = deployLive($project, $team['owner']);

    return [
        'team' => $team,
        'project' => $project->fresh(),
        'node' => $node,
        'live' => $live,
        'schedule' => scheduleFor($project, [
            'cron_expression' => '*/15 * * * *',
            'next_run_at' => CarbonImmutable::parse('2026-11-24 10:15:00', 'UTC'),
            ...$schedule,
        ]),
    ];
}

function dispatchSchedules(): array
{
    return app(DispatchSchedulesAction::class)->handle();
}

test('a due slot becomes one run and one RunJob command in the agent contract shape', function (): void {
    ['schedule' => $schedule, 'live' => $live, 'node' => $node] = dueSchedule();

    expect(dispatchSchedules())->toMatchArray(['started' => 1]);

    $run = ScheduleRun::query()->sole();
    $command = AgentCommand::query()->where('type', AgentCommandType::RunJob)->sole();
    $deployPayload = deployPayloadOf($live);
    $fixture = agentCommandFixture('run-job')['data'][0];

    expect($run)
        ->status->toBe(ScheduleRunStatus::Queued)
        ->scheduled_for->toIso8601ZuluString()->toBe('2026-11-24T10:15:00Z')
        ->deadline_at->toIso8601ZuluString()->toBe('2026-11-24T10:25:00Z')
        ->agent_command_id->toBe($command->id)
        ->deployment_id->toBe($live->id);

    expect($command)
        ->agent_node_id->toBe($node->id)
        ->project_id->toBe($schedule->project_id)
        ->deployment_id->toBe($live->id)
        ->status->toBe(AgentCommandStatus::Pending)
        ->idempotency_key->toBe("schedule-run:{$schedule->id}:2026-11-24T10:15:00Z")
        ->expires_at->toIso8601ZuluString()->toBe('2026-11-24T10:25:00Z');

    // Same keys as sakala-agent examples/commands/run-job.json, except the
    // reuse key, which is only sent for a live deployment that reused an image.
    expect(array_keys($command->payload))->toEqualCanonicalizing(array_values(array_diff(array_keys($fixture['payload']), ['reuse_image_from_deployment_id'])))
        ->and($command->payload)->toMatchArray([
            'command' => 'php artisan report:daily',
            'timeout_seconds' => 300,
            'commit_sha' => $live->commit_sha,
            'container_port' => $live->container_port,
            'resources' => $deployPayload['resources'],
            'log_bounds' => $deployPayload['log_bounds'],
        ])
        ->and(Crypt::decryptString($command->payload['environment']['APP_KEY']))->toBe('base64:secret-value');

    expect($schedule->fresh()->next_run_at->toIso8601ZuluString())->toBe('2026-11-24T10:30:00Z');

    // Running again in the same minute records nothing new.
    dispatchSchedules();
    expect(ScheduleRun::query()->count())->toBe(1);
});

test('the node receives the RunJob with the variables decrypted', function (): void {
    ['node' => $node] = dueSchedule();
    dispatchSchedules();

    $response = $this->withHeaders(workerNodeHeaders($node))->getJson('/api/agent/v1/commands')->assertOk();
    $job = collect($response->json('data'))->firstWhere('type', 'RunJob');

    expect($job['payload']['environment']['APP_KEY'])->toBe('base64:secret-value')
        ->and($job['payload']['timeout_seconds'])->toBe(300);
});

test('a live deployment that reused an image sends the deployment that built it', function (): void {
    ['team' => $team, 'project' => $project, 'live' => $first] = dueSchedule();
    addProcess($project->fresh());
    $reused = deployLive($project->fresh(), $team['owner']);

    expect($reused->imageBuilderId())->toBe($first->id);

    dispatchSchedules();

    expect(AgentCommand::query()->where('type', AgentCommandType::RunJob)->sole()->payload['reuse_image_from_deployment_id'])->toBe($first->id);
});

test('a slot while the previous run is open is skipped as an overlap', function (): void {
    ['schedule' => $schedule] = dueSchedule();
    dispatchSchedules();

    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:30:05', 'UTC'));
    dispatchSchedules();

    expect(ScheduleRun::query()->orderBy('scheduled_for')->get()->map(fn (ScheduleRun $run): array => [$run->status, $run->reason])->all())
        ->toBe([[ScheduleRunStatus::Queued, null], [ScheduleRunStatus::Skipped, ScheduleRunReason::Overlap]]);
});

test('a slot past its start deadline is missed, never run late, and the dispatcher moves on', function (): void {
    ['schedule' => $schedule] = dueSchedule(schedule: ['cron_expression' => '0 * * * *', 'next_run_at' => CarbonImmutable::parse('2026-11-24 07:00:00', 'UTC')]);

    // Away since 07:00; now 10:15, so 07:00 and the latest slot 10:00 (deadline 10:10) are both missed.
    dispatchSchedules();

    expect(ScheduleRun::query()->orderBy('scheduled_for')->get()->map(fn (ScheduleRun $run): array => [$run->scheduled_for->format('H:i'), $run->status, $run->reason])->all())
        ->toBe([
            ['07:00', ScheduleRunStatus::Missed, ScheduleRunReason::DispatcherLate],
            ['10:00', ScheduleRunStatus::Missed, ScheduleRunReason::DispatcherLate],
        ])
        ->and(AgentCommand::query()->where('type', AgentCommandType::RunJob)->count())->toBe(0)
        ->and($schedule->fresh()->next_run_at->toIso8601ZuluString())->toBe('2026-11-24T11:00:00Z');
});

test('a late dispatcher still runs the latest slot inside its deadline', function (): void {
    dueSchedule(schedule: ['cron_expression' => '0 * * * *', 'next_run_at' => CarbonImmutable::parse('2026-11-24 09:00:00', 'UTC')]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:04:00', 'UTC'));

    dispatchSchedules();

    expect(ScheduleRun::query()->orderBy('scheduled_for')->pluck('status')->all())
        ->toBe([ScheduleRunStatus::Missed, ScheduleRunStatus::Queued]);
});

test('the start deadline is the interval when that is shorter', function (): void {
    ['schedule' => $schedule] = dueSchedule(profile: ['min_schedule_interval_minutes' => 1], schedule: ['cron_expression' => '*/5 * * * *']);

    dispatchSchedules();

    expect(ScheduleRun::query()->sole()->deadline_at->toIso8601ZuluString())->toBe('2026-11-24T10:20:00Z');
});

test('a workspace at max_concurrent_job_runs makes the run wait, then starts it when a slot frees', function (): void {
    ['schedule' => $first, 'project' => $project] = dueSchedule();
    $second = scheduleFor($project, ['cron_expression' => '*/15 * * * *', 'next_run_at' => CarbonImmutable::parse('2026-11-24 10:15:00', 'UTC')]);

    dispatchSchedules();

    $runs = ScheduleRun::query()->get()->keyBy('schedule_id');
    $queued = $runs->first(fn (ScheduleRun $run): bool => $run->status === ScheduleRunStatus::Queued);
    $waiting = $runs->first(fn (ScheduleRun $run): bool => $run->status === ScheduleRunStatus::Waiting);

    expect($queued)->not->toBeNull()
        ->and($waiting)->not->toBeNull()
        ->and($waiting->reason)->toBe(ScheduleRunReason::ConcurrencyLimit)
        ->and($waiting->agent_command_id)->toBeNull();

    $queued->update(['status' => ScheduleRunStatus::Succeeded]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:16:05', 'UTC'));
    dispatchSchedules();

    expect($waiting->fresh()->status)->toBe(ScheduleRunStatus::Queued)
        ->and($waiting->fresh()->agent_command_id)->not->toBeNull();
});

test('a waiting run past its deadline is missed', function (): void {
    ['project' => $project] = dueSchedule(profile: ['max_concurrent_job_runs' => 0]);

    dispatchSchedules();
    expect(ScheduleRun::query()->sole()->status)->toBe(ScheduleRunStatus::Waiting);

    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:25:00', 'UTC'));
    dispatchSchedules();

    expect(ScheduleRun::query()->sole())
        ->status->toBe(ScheduleRunStatus::Missed)
        ->reason->toBe(ScheduleRunReason::ConcurrencyLimit);
});

test('a suspended project, no live deployment, or a node without scheduled-jobs skips the slot', function (string $case, ScheduleRunReason $reason): void {
    $capabilities = $case === 'node' ? array_values(array_diff(WORKER_SCHEDULE_CAPABILITIES, ['scheduled-jobs'])) : WORKER_SCHEDULE_CAPABILITIES;
    ['project' => $project] = dueSchedule($capabilities);

    match ($case) {
        'suspended' => $project->update(['status' => ProjectStatus::Suspended]),
        'stopped' => $project->update(['active_deployment_id' => null]),
        default => null,
    };

    dispatchSchedules();

    expect(ScheduleRun::query()->sole())
        ->status->toBe(ScheduleRunStatus::Skipped)
        ->reason->toBe($reason)
        ->and(AgentCommand::query()->where('type', AgentCommandType::RunJob)->count())->toBe(0);
})->with([
    'suspended project' => ['suspended', ScheduleRunReason::ProjectSuspended],
    'no live deployment' => ['stopped', ScheduleRunReason::NoLiveDeployment],
    'older node' => ['node', ScheduleRunReason::NodeUnsupported],
]);

test('a sleeping project still runs its schedule and is not woken', function (): void {
    ['project' => $project] = dueSchedule();
    sleepProject($project);

    dispatchSchedules();

    expect(ScheduleRun::query()->sole()->status)->toBe(ScheduleRunStatus::Queued)
        ->and(AgentCommand::query()->where('type', AgentCommandType::WakeProject)->count())->toBe(0);
});

test('a disabled schedule is not dispatched', function (): void {
    dueSchedule(schedule: ['enabled' => false]);

    dispatchSchedules();

    expect(ScheduleRun::query()->count())->toBe(0);
});

test('the claim lease of a RunJob covers its timeout, the agent grace, and the lease grace', function (): void {
    ['node' => $node] = dueSchedule(schedule: ['timeout_seconds' => 600]);
    dispatchSchedules();
    $command = AgentCommand::query()->where('type', AgentCommandType::RunJob)->sole();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim")->assertOk();

    $grace = (int) config('sakala.agent.lease_grace_seconds');

    expect((int) now()->diffInSeconds($command->fresh()->lease_expires_at))->toBe(600 + 30 + $grace)
        ->and(ScheduleRun::query()->sole())
        ->status->toBe(ScheduleRunStatus::Running)
        ->started_at->not->toBeNull();
});

test('the schedules:dispatch command is scheduled every minute on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'schedules:dispatch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();

    $this->artisan('schedules:dispatch')->assertSuccessful();
});

test('only the newest runs_kept runs of a schedule are kept', function (): void {
    config(['sakala.schedules.runs_kept' => 3]);
    ['schedule' => $schedule] = dueSchedule();

    foreach (range(1, 5) as $minutes) {
        ScheduleRun::factory()->create(['schedule_id' => $schedule->id, 'scheduled_for' => CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC')->addMinutes($minutes), 'created_at' => now()->subHours(6 - $minutes)]);
    }

    dispatchSchedules();

    expect(ScheduleRun::query()->where('schedule_id', $schedule->id)->count())->toBe(3)
        ->and(ScheduleRun::query()->where('status', ScheduleRunStatus::Queued)->count())->toBe(1);
});
