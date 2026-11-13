<?php

declare(strict_types=1);

use App\Actions\Project\SleepIdleProjectsAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sakala.project.idle_sleep_minutes' => 30]);
});

function idleSleepCommands(): Builder
{
    return AgentCommand::query()->where('type', AgentCommandType::SleepProject);
}

test('an idle project gets one idle SleepProject on its node, with an audit event', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subMinutes(31)]);

    $this->artisan('projects:sleep-idle')
        ->expectsOutputToContain('Asked 1 idle project(s) to sleep.')
        ->assertSuccessful();

    $command = idleSleepCommands()->sole();

    expect($command->project_id)->toBe($project->id)
        ->and($command->deployment_id)->toBe($deployment->id)
        ->and($command->agent_node_id)->toBe($node->id)
        ->and($command->status)->toBe(AgentCommandStatus::Pending)
        ->and($command->payload)->toBe([])
        ->and($command->request_context)->toBe([
            'reason' => 'idle',
            'actor_type' => 'system',
            'actor_id' => 'scheduler:sleep-idle',
        ])
        ->and($command->isIdleSleep())->toBeTrue()
        ->and($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running);

    $audit = AuditEvent::query()->where('action', 'project.idle_sleep_requested')->sole();

    expect($audit->subject_id)->toBe($project->id)
        ->and($audit->actor_type)->toBe('system')
        ->and($audit->actor_id)->toBe('scheduler:sleep-idle')
        ->and($audit->metadata)->toMatchArray([
            'command_id' => $command->id,
            'deployment_id' => $deployment->id,
            'idle_sleep_minutes' => 30,
        ]);

    // The open command keeps the next sweeps from asking again.
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');
    $this->travel(1)->minutes();
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');

    expect(idleSleepCommands()->count())->toBe(1);
});

test('nothing sleeps while the setting is 0', function (): void {
    config(['sakala.project.idle_sleep_minutes' => 0]);
    $node = idleSleepNode();
    idleSleepProject($node, ['last_request_at' => now()->subDay()]);

    $this->artisan('projects:sleep-idle')
        ->expectsOutputToContain('Idle sleep is off')
        ->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe(0);
});

test('without a request the deployment completion is the last activity', function (int $finishedMinutesAgo, int $expected): void {
    $node = idleSleepNode();
    idleSleepProject($node, deploymentOverrides: ['finished_at' => now()->subMinutes($finishedMinutesAgo)]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe($expected);
})->with([
    'deployed long ago' => [31, 1],
    'deployed recently' => [29, 0],
]);

test('a recent request or a newer deployment keeps a project awake', function (int $requestMinutesAgo, int $finishedMinutesAgo): void {
    $node = idleSleepNode();
    idleSleepProject(
        $node,
        ['last_request_at' => now()->subMinutes($requestMinutesAgo)],
        ['finished_at' => now()->subMinutes($finishedMinutesAgo)],
    );

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe(0);
})->with([
    'request inside the window' => [29, 60],
    // A request before the latest deployment says nothing about the new one.
    'old request, new deployment' => [24 * 60, 5],
]);

test('a recent wake or restart counts as activity', function (AgentCommandType $type): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => $type,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinutes(10),
    ]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();
    expect(idleSleepCommands()->count())->toBe(0);

    $this->travel(21)->minutes();
    $this->artisan('projects:sleep-idle')->assertSuccessful();
    expect(idleSleepCommands()->count())->toBe(1);
})->with([AgentCommandType::WakeProject, AgentCommandType::RestartProject]);

test('only nodes with idle-sleep that report traffic and are reachable take part', function (array $node): void {
    $node = idleSleepNode($node);
    idleSleepProject($node, ['last_request_at' => now()->subDay()]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe(0);
})->with([
    'no capability' => [['capabilities' => ['docker-runtime']]],
    // Without a traffic list nobody knows whether the project is idle.
    'no traffic list' => [['metadata' => ['workloads' => ['active' => 1]]]],
    'no metadata yet' => [['metadata' => null]],
    'offline' => [['status' => AgentNodeStatus::Offline]],
    'revoked' => [['auth_status' => AgentAuthStatus::Revoked]],
]);

test('projects that are not plainly running are left alone', function (array $overrides): void {
    $node = idleSleepNode();
    idleSleepProject($node, ['last_request_at' => now()->subDay(), ...$overrides]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe(0);
})->with([
    'suspended' => [['status' => ProjectStatus::Suspended]],
    'already sleeping' => [['runtime_status' => RuntimeStatus::Sleeping]],
    'stopped' => [['runtime_status' => RuntimeStatus::Stopped]],
    'crashed' => [['runtime_status' => RuntimeStatus::Crashed]],
    'deploying' => [['runtime_status' => RuntimeStatus::Deploying]],
]);

test('a deleted project is never put to sleep', function (): void {
    $node = idleSleepNode();
    [$project] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    $project->delete();

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe(0);
});

test('a deployment in progress keeps the project awake', function (): void {
    $node = idleSleepNode();
    [$project] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 2,
    ]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->count())->toBe(0);
});

test('an open lifecycle command keeps the project out of the sweep', function (AgentCommandType $type, AgentCommandStatus $status): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => $type,
        'status' => $status,
    ]);

    $this->artisan('projects:sleep-idle')->assertSuccessful();

    expect(idleSleepCommands()->where('project_id', $project->id)->count())
        ->toBe($type === AgentCommandType::SleepProject ? 1 : 0);
})->with([
    'sleep pending' => [AgentCommandType::SleepProject, AgentCommandStatus::Pending],
    'wake claimed' => [AgentCommandType::WakeProject, AgentCommandStatus::Claimed],
    'stop running' => [AgentCommandType::StopProject, AgentCommandStatus::Running],
    'restart pending' => [AgentCommandType::RestartProject, AgentCommandStatus::Pending],
]);

test('a failed idle sleep is asked again one idle window later', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, ['failed_at' => now(), 'error_code' => 'runtime_unavailable']);

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');

    $this->travel(29)->minutes();
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');

    $this->travel(1)->minutes();
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');

    expect(idleSleepCommands()->where('status', AgentCommandStatus::Pending)->count())->toBe(1);
});

test('every further failure in a row doubles the wait, up to a day', function (): void {
    $node = idleSleepNode();
    [$project] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);

    // Waits after the 1st to 7th failure with a 30 minute window.
    foreach ([30, 60, 120, 240, 480, 960, 1440] as $wait) {
        $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');
        idleSleepCommands()->where('status', AgentCommandStatus::Pending)->sole()
            ->update(['status' => AgentCommandStatus::Failed, 'failed_at' => now(), 'error_code' => 'runtime_unavailable']);

        $this->travel($wait - 1)->minutes();
        $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');
        $this->travel(1)->minutes();
    }

    // Capped: the 8th failure still waits a day.
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');
    idleSleepCommands()->where('status', AgentCommandStatus::Pending)->sole()
        ->update(['status' => AgentCommandStatus::Failed, 'failed_at' => now(), 'error_code' => 'runtime_unavailable']);
    $this->travel(24 * 60 - 1)->minutes();
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');
    $this->travel(1)->minutes();
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');

    expect(idleSleepCommands()->where('project_id', $project->id)->count())->toBe(9);
});

test('a lifecycle success on the deployment starts the count again', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDays(2)]);
    foreach ([3, 2, 1] as $hoursAgo) {
        idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, ['failed_at' => now()->subHours($hoursAgo)->subDay(), 'error_code' => 'runtime_unavailable']);
    }
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::RestartProject,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subHours(2),
    ]);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, ['failed_at' => now()->subMinutes(31), 'error_code' => 'runtime_unavailable']);

    // One failure since the restart: one window, not the eight of four in a row.
    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');
});

test('a missing container stops idle sleep for that deployment', function (): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, [
        'failed_at' => now(),
        'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR,
    ]);

    foreach ([1, 60, 24 * 60, 7 * 24 * 60] as $minutes) {
        $this->travel($minutes)->minutes();
        $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 0 idle project(s) to sleep.');
    }

    expect($project->fresh()->isIdleSleepBlocked())->toBeTrue();
});

test('a wake or restart that succeeds after the missing container lifts the stop', function (AgentCommandType $type): void {
    $node = idleSleepNode();
    [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDays(2)]);
    idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, [
        'failed_at' => now()->subDay(),
        'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR,
    ]);
    AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => $type,
        'status' => AgentCommandStatus::Succeeded,
        'completed_at' => now()->subMinutes(31),
    ]);

    expect($project->fresh()->isIdleSleepBlocked())->toBeFalse();

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');
})->with([AgentCommandType::WakeProject, AgentCommandType::RestartProject]);

test('a new deployment is asked to sleep although the previous one had no container', function (): void {
    $node = idleSleepNode();
    [$project, $old] = idleSleepProject($node, ['last_request_at' => now()->subDays(2)]);
    idleSleepCommand($project, $old, AgentCommandStatus::Failed, [
        'failed_at' => now()->subDay(),
        'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR,
    ]);
    $new = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 2,
        'finished_at' => now()->subMinutes(31),
    ]);
    $project->update(['active_deployment_id' => $new->id]);

    expect($project->fresh()->isIdleSleepBlocked())->toBeFalse();

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');

    expect(idleSleepCommands()->where('status', AgentCommandStatus::Pending)->sole()->deployment_id)->toBe($new->id);
});

test('a project waiting after failures does not hold up the others', function (): void {
    $node = idleSleepNode();
    [$waiting, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    idleSleepCommand($waiting, $deployment, AgentCommandStatus::Failed, ['failed_at' => now(), 'error_code' => 'runtime_unavailable']);
    [$blocked, $blockedDeployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
    idleSleepCommand($blocked, $blockedDeployment, AgentCommandStatus::Failed, ['failed_at' => now(), 'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR]);
    [$idle] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 1 idle project(s) to sleep.');

    expect(idleSleepCommands()->where('status', AgentCommandStatus::Pending)->sole()->project_id)->toBe($idle->id);
});

test('the sweep reads the failures of a batch in one query, however many projects wait', function (): void {
    $node = idleSleepNode();
    $queriesFor = function (int $projects) use ($node): int {
        foreach (range(1, $projects) as $ignored) {
            [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
            idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, ['failed_at' => now(), 'error_code' => 'runtime_unavailable']);
            [$project, $deployment] = idleSleepProject($node, ['last_request_at' => now()->subDay()]);
            idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, ['failed_at' => now(), 'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SleepIdleProjectsAction::class)->handle();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $few = $queriesFor(2);
    $many = $queriesFor(6);

    expect($many)->toBe($few)
        ->and(idleSleepCommands()->where('status', AgentCommandStatus::Pending)->count())->toBe(0);
});

test('the sweep picks exactly the idle projects among many', function (): void {
    $node = idleSleepNode();
    [$idleA] = idleSleepProject($node, ['last_request_at' => now()->subHours(2)]);
    [$idleB] = idleSleepProject($node);
    idleSleepProject($node, ['last_request_at' => now()->subMinutes(5)]);
    idleSleepProject($node, ['status' => ProjectStatus::Suspended]);

    $this->artisan('projects:sleep-idle')->expectsOutputToContain('Asked 2 idle project(s) to sleep.');

    expect(idleSleepCommands()->pluck('project_id')->sort()->values()->all())
        ->toBe(collect([$idleA->id, $idleB->id])->sort()->values()->all());
});

test('the sweep runs every minute without overlap on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'projects:sleep-idle'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
