<?php

declare(strict_types=1);

use App\Actions\Admin\SuspendProjectAction;
use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Actions\Schedule\DispatchSchedulesAction;
use App\Data\Admin\ProjectControlData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\ScheduleHealth;
use App\Enums\SchedulePauseReason;
use App\Enums\ScheduleRunReason;
use App\Enums\ScheduleRunStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleRun;
use App\Models\User;
use App\Notifications\Project\ProjectAlertMailNotification;
use App\Notifications\UserAlertNotification;
use App\Support\Project\WorkloadAudit;
use App\Support\Schedule\ScheduleRunFailureMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableWorkersAndSchedules();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:15:20', 'UTC'));
});

/** @return array{schedule: ProjectSchedule, node: AgentNode, run: ScheduleRun, command: AgentCommand, team: array<string, mixed>, project: Project} */
function claimedRun(array $schedule = []): array
{
    ['team' => $team, 'project' => $project, 'node' => $node] = workerProject();
    deployLive($project, $team['owner']);
    $model = scheduleFor($project, ['cron_expression' => '*/15 * * * *', 'next_run_at' => now()->startOfMinute(), ...$schedule]);

    app(DispatchSchedulesAction::class)->handle();
    $run = ScheduleRun::query()->where('schedule_id', $model->id)->latest()->firstOrFail();
    $command = runJobCommandOf($run->id);

    test()->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim")->assertOk();

    return ['schedule' => $model, 'node' => $node, 'run' => $run->fresh(), 'command' => $command->fresh(), 'team' => $team, 'project' => $project->fresh()];
}

function jobFinished(AgentNode $node, AgentCommand $command, ?int $exitCode, bool $timedOut = false, bool $oom = false): void
{
    test()->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/events", ['events' => [[
        'level' => 'info',
        'type' => 'job.finished',
        'message' => 'job finished',
        'metadata' => ['exit_code' => $exitCode, 'duration_ms' => 1570, 'oom_killed' => $oom, 'timed_out' => $timedOut],
        'occurred_at' => now()->toIso8601ZuluString(),
    ]]])->assertOk();
}

/** Run the schedule's next slot through to a failure reported by the agent. */
function failNextRun(ProjectSchedule $schedule, AgentNode $node, string $code = 'job_failed'): void
{
    $schedule->refresh()->update(['next_run_at' => now()->startOfMinute()]);
    app(DispatchSchedulesAction::class)->handle();
    $command = runJobCommandOf(ScheduleRun::query()->where('schedule_id', $schedule->id)->where('status', ScheduleRunStatus::Queued)->sole()->id);

    test()->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim")->assertOk();
    test()->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => $code, 'error_message' => 'the job exited with exit code 3'])->assertSuccessful();
    test()->travel(16)->minutes();
}

test('a job that exits 0 succeeds with its exit code, duration, and memory flag', function (): void {
    ['node' => $node, 'run' => $run, 'command' => $command] = claimedRun();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/complete", [
        'result' => ['exit_code' => 0, 'duration_ms' => 1570, 'oom_killed' => false],
    ])->assertSuccessful();

    expect($run->fresh())
        ->status->toBe(ScheduleRunStatus::Succeeded)
        ->exit_code->toBe(0)
        ->duration_ms->toBe(1570)
        ->oom_killed->toBeFalse()
        ->finished_at->not->toBeNull();
});

test('a failed job keeps the exit code of job.finished as a field, and its events stay off the deployment timeline', function (): void {
    ['node' => $node, 'run' => $run, 'command' => $command] = claimedRun();

    jobFinished($node, $command, 3);
    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", agentFailureFixture('job-failures', 0))->assertSuccessful();

    expect($run->fresh())
        ->status->toBe(ScheduleRunStatus::Failed)
        ->exit_code->toBe(3)
        ->error_code->toBe('job_failed')
        ->and($command->fresh()->reports()->where('type', 'job.finished')->exists())->toBeTrue()
        ->and(DeploymentEvent::query()->where('type', 'job.finished')->exists())->toBeFalse();

    $this->actingAs(claimedRunOwner($run))->getJson(runUrl($run))
        ->assertOk()
        ->assertJsonPath('data.0.exit_code', 3)
        ->assertJsonPath('data.0.error_code', 'job_failed');
});

test('every job failure body of the agent maps to a run status and a message', function (int $index, ScheduleRunStatus $status): void {
    ['node' => $node, 'run' => $run, 'command' => $command] = claimedRun();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", agentFailureFixture('job-failures', $index))->assertSuccessful();

    expect($run->fresh()->status)->toBe($status)
        ->and(ScheduleRunFailureMessage::for($run->fresh()))->not->toBeNull()->not->toContain('`');
})->with([
    'job_failed' => [0, ScheduleRunStatus::Failed],
    'job_failed with OOM' => [1, ScheduleRunStatus::Failed],
    'job_timeout' => [2, ScheduleRunStatus::TimedOut],
    'job_image_missing (no image)' => [3, ScheduleRunStatus::Failed],
    'job_image_missing (wrong image)' => [4, ScheduleRunStatus::Failed],
]);

test('a cancelled job is cancelled, not a failure', function (): void {
    ['node' => $node, 'run' => $run, 'command' => $command, 'schedule' => $schedule] = claimedRun();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'runtime_cancelled', 'error_message' => 'cancelled'])->assertSuccessful();

    expect($run->fresh()->status)->toBe(ScheduleRunStatus::Cancelled)
        ->and($schedule->fresh()->consecutive_failures)->toBe(0);
});

test('an unclaimed RunJob past its deadline is missed, a claimed one past its lease is lost', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    deployLive($project, $team['owner']);
    $schedule = scheduleFor($project, ['cron_expression' => '*/15 * * * *', 'next_run_at' => now()->startOfMinute()]);
    app(DispatchSchedulesAction::class)->handle();
    $missed = ScheduleRun::query()->sole();

    $this->travel(11)->minutes();
    app(ExpireAgentCommandsAction::class)->handle();

    expect($missed->fresh())->status->toBe(ScheduleRunStatus::Missed)->reason->toBe(ScheduleRunReason::NotStarted);

    ['run' => $run, 'command' => $command, 'schedule' => $lostSchedule] = claimedRun();
    $this->travelTo($command->lease_expires_at->addSecond());
    app(ExpireAgentCommandsAction::class)->handle();

    expect($run->fresh()->status)->toBe(ScheduleRunStatus::Lost)
        ->and($lostSchedule->fresh()->consecutive_failures)->toBe(1)
        ->and($lostSchedule->fresh()->health)->toBe(ScheduleHealth::Failing);
});

test('members are told when a schedule starts failing and when it recovers, not on every failure', function (): void {
    ['schedule' => $schedule, 'node' => $node, 'command' => $command] = claimedRun();
    Notification::fake();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", agentFailureFixture('job-failures', 0))->assertSuccessful();
    $this->travel(16)->minutes();
    failNextRun($schedule, $node);

    // owner and member, once.
    Notification::assertSentTimes(UserAlertNotification::class, 2);
    Notification::assertSentTimes(ProjectAlertMailNotification::class, 1);
    expect($schedule->fresh())->consecutive_failures->toBe(2)->health->toBe(ScheduleHealth::Failing);

    $schedule->refresh()->update(['next_run_at' => now()->startOfMinute()]);
    app(DispatchSchedulesAction::class)->handle();
    $next = runJobCommandOf(ScheduleRun::query()->where('status', ScheduleRunStatus::Queued)->sole()->id);
    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$next->id}/claim")->assertOk();
    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$next->id}/complete", ['result' => ['exit_code' => 0, 'duration_ms' => 10, 'oom_killed' => false]])->assertSuccessful();

    Notification::assertSentTimes(UserAlertNotification::class, 4);
    expect($schedule->fresh())->consecutive_failures->toBe(0)->health->toBe(ScheduleHealth::Healthy);
});

test('a schedule is paused after pause_after_failures failures in a row, with an audit event and a notice', function (): void {
    config(['sakala.schedules.pause_after_failures' => 3]);
    ['schedule' => $schedule, 'node' => $node, 'command' => $command] = claimedRun();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", agentFailureFixture('job-failures', 2))->assertSuccessful();
    $this->travel(16)->minutes();
    failNextRun($schedule, $node);

    Notification::fake();
    failNextRun($schedule, $node);

    expect($schedule->fresh())
        ->enabled->toBeFalse()
        ->next_run_at->toBeNull()
        ->paused_at->not->toBeNull()
        ->pause_reason->toBe(SchedulePauseReason::ConsecutiveFailures)
        ->consecutive_failures->toBe(3)
        ->and(AuditEvent::query()->where('action', WorkloadAudit::SCHEDULE_PAUSED)->sole()->metadata)->toMatchArray(['consecutive_failures' => 3, 'reason' => 'consecutive_failures']);

    Notification::assertSentTimes(UserAlertNotification::class, 2);
    Notification::assertSentTimes(ProjectAlertMailNotification::class, 1);

    // Paused: the dispatcher records nothing more.
    $this->travel(1)->hours();
    app(DispatchSchedulesAction::class)->handle();
    expect(ScheduleRun::query()->where('schedule_id', $schedule->id)->count())->toBe(3);
});

test('job output is stored on the live deployment with process job and served per run', function (): void {
    ['node' => $node, 'run' => $run, 'command' => $command] = claimedRun();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/logs", ['logs' => [
        ['stream' => 'stdout', 'message' => '[job] Report sent', 'recorded_at' => now()->toIso8601ZuluString(), 'process' => 'job'],
    ]])->assertOk();

    expect(DeploymentLog::query()->where('agent_command_id', $command->id)->sole())
        ->process->toBe('job')
        ->deployment_id->toBe($run->deployment_id);

    $this->actingAs(claimedRunOwner($run))->getJson(runUrl($run)."/{$run->id}/logs")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', '[job] Report sent')
        ->assertJsonPath('data.0.process', 'job');
});

test('run now starts a run under the same rules, is audited, and refuses an overlap', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    deployLive($project, $team['owner']);
    $schedule = scheduleFor($project, ['enabled' => false]);
    $url = "/api/v1/app/projects/{$project->id}/schedules/{$schedule->id}/runs";

    $this->actingAs($team['viewer'])->postJson($url)->assertForbidden();

    $runId = $this->actingAs($team['member'])->postJson($url)
        ->assertCreated()
        ->assertJsonPath('data.trigger', 'manual')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.scheduled_for', null)
        ->json('data.id');

    expect(runJobCommandOf($runId)->idempotency_key)->toBe("schedule-run:{$schedule->id}:manual:{$runId}")
        ->and(AuditEvent::query()->where('action', WorkloadAudit::SCHEDULE_RUN_REQUESTED)->sole()->metadata['run_id'])->toBe($runId);

    $this->actingAs($team['member'])->postJson($url)
        ->assertConflict()
        ->assertJsonPath('code', 'SCHEDULE_RUN_REFUSED')
        ->assertJsonPath('reason', 'overlap');
});

test('run now is refused without a live deployment', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $schedule = scheduleFor($project);

    $this->actingAs($team['owner'])->postJson("/api/v1/app/projects/{$project->id}/schedules/{$schedule->id}/runs")
        ->assertConflict()
        ->assertJsonPath('reason', 'no_live_deployment');

    expect(ScheduleRun::query()->count())->toBe(0);
});

test('run now on a suspended project answers with the reason', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    deployLive($project, $team['owner']);
    $project->update(['status' => ProjectStatus::Suspended]);
    $schedule = scheduleFor($project);

    $this->actingAs($team['member'])->postJson("/api/v1/app/projects/{$project->id}/schedules/{$schedule->id}/runs")
        ->assertConflict()
        ->assertJsonPath('code', 'SCHEDULE_RUN_REFUSED')
        ->assertJsonPath('reason', 'project_suspended');
});

test('stop run cancels an unclaimed run at once and asks the node to stop a running one', function (): void {
    ['team' => $team, 'project' => $project, 'node' => $node] = workerProject();
    deployLive($project, $team['owner']);
    $schedule = scheduleFor($project);
    $runs = "/api/v1/app/projects/{$project->id}/schedules/{$schedule->id}/runs";

    $queued = $this->actingAs($team['member'])->postJson($runs)->json('data.id');
    $this->actingAs($team['member'])->postJson("{$runs}/{$queued}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(runJobCommandOf($queued)->status)->toBe(AgentCommandStatus::Cancelled);

    $running = $this->actingAs($team['member'])->postJson($runs)->json('data.id');
    $command = runJobCommandOf($running);
    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/claim")->assertOk();

    $this->actingAs($team['member'])->postJson("{$runs}/{$running}/cancel")->assertAccepted()->assertJsonPath('data.status', 'running');
    expect($this->withHeaders(workerNodeHeaders($node))->getJson('/api/agent/v1/commands')->json('cancel_requested'))->toContain($command->id);

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'runtime_cancelled', 'error_message' => 'cancelled'])->assertSuccessful();
    $this->actingAs($team['member'])->postJson("{$runs}/{$running}/cancel")->assertConflict()->assertJsonPath('code', 'SCHEDULE_RUN_NOT_OPEN');
});

test('deleting the project stops its runs and deletes its schedules', function (): void {
    ['team' => $team, 'project' => $project, 'run' => $run, 'command' => $command] = claimedRun();

    $this->actingAs($team['owner'])->deleteJson("/api/v1/app/projects/{$project->id}")->assertSuccessful();

    expect(ProjectSchedule::query()->count())->toBe(0)
        ->and(ScheduleRun::query()->count())->toBe(0)
        ->and($command->fresh()->cancel_requested_at)->not->toBeNull()
        ->and(AgentCommand::query()->where('type', AgentCommandType::StopProject)->exists())->toBeTrue();
});

test('suspending the project stops its running job; the node then reports the cancellation', function (): void {
    ['team' => $team, 'project' => $project, 'run' => $run, 'command' => $command, 'node' => $node] = claimedRun();

    app(SuspendProjectAction::class)->handle($project, $team['admin'], new ProjectControlData('Penyalahgunaan'));

    expect($command->fresh()->cancel_requested_at)->not->toBeNull();

    $this->withHeaders(workerNodeHeaders($node))->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'runtime_cancelled', 'error_message' => 'cancelled'])->assertSuccessful();

    expect($run->fresh()->status)->toBe(ScheduleRunStatus::Cancelled);
});

test('the run history is paged newest first', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $schedule = scheduleFor($project);

    foreach (range(1, 3) as $hour) {
        ScheduleRun::factory()->create(['schedule_id' => $schedule->id, 'scheduled_for' => now()->subHours($hour), 'created_at' => now()->subHours($hour)]);
    }

    $this->actingAs($team['viewer'])->getJson("/api/v1/app/projects/{$project->id}/schedules/{$schedule->id}/runs?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.scheduled_for', now()->subHour()->toIso8601ZuluString());
});

function runUrl(ScheduleRun $run): string
{
    $projectId = $run->project_id;

    return "/api/v1/app/projects/{$projectId}/schedules/{$run->schedule_id}/runs";
}

function claimedRunOwner(ScheduleRun $run): User
{
    return $run->project->workspace->owners()->firstOrFail();
}

/** @return array{error_code: string, error_message: string} */
function agentFailureFixture(string $name, int $index): array
{
    $bodies = json_decode((string) file_get_contents(base_path("tests/Fixtures/agent-protocol-v4/failures/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);

    return $bodies[$index];
}
