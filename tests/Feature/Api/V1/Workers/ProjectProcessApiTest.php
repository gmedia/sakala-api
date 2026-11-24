<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\ProjectProcess;
use App\Support\Project\WorkloadAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableWorkersAndSchedules();
});

function processUrl(string $projectId, ?string $processId = null): string
{
    return "/api/v1/app/projects/{$projectId}/processes".($processId === null ? '' : "/{$processId}");
}

test('a member adds a worker process, which asks for a redeploy and is audited', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    deployLive($project, $team['owner']);

    $response = $this->actingAs($team['member'])->postJson(processUrl($project->id), [
        'name' => 'queue',
        'command' => '  php artisan queue:work --sleep=3 --tries=3 --max-time=3600  ',
        'memory_mb' => 192,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'queue')
        ->assertJsonPath('data.command', 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600')
        ->assertJsonPath('data.replicas', 1)
        ->assertJsonPath('data.memory_mb', 192)
        ->assertJsonPath('data.cpu_millis', null)
        ->assertJsonPath('data.crash', null)
        ->assertJsonPath('data.redeploy_required', true);

    expect($project->fresh()->processes_fingerprint)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', WorkloadAudit::PROCESS_CREATED)->where('subject_id', $project->id)->sole()->metadata)
        ->toMatchArray(['name' => 'queue', 'replicas' => 1, 'workspace_id' => $team['workspace']->id]);

    $this->actingAs($team['viewer'])->getJson(processUrl($project->id))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'queue');
});

test('only owners and members change processes; viewers and outsiders cannot', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $process = addProcess($project);

    $this->actingAs($team['viewer'])->postJson(processUrl($project->id), ['name' => 'mailer', 'command' => 'npm run mailer'])->assertForbidden();
    $this->actingAs($team['viewer'])->patchJson(processUrl($project->id, $process->id), ['replicas' => 0])->assertForbidden();
    $this->actingAs($team['viewer'])->deleteJson(processUrl($project->id, $process->id))->assertForbidden();
    $this->actingAs($team['outsider'])->getJson(processUrl($project->id))->assertForbidden();

    expect(ProjectProcess::query()->count())->toBe(1);
});

test('names, commands, and replicas follow the agent contract', function (array $body, string $field): void {
    ['team' => $team, 'project' => $project] = workerProject();
    addProcess($project, ['name' => 'queue', 'replicas' => 0]);

    $this->actingAs($team['owner'])->postJson(processUrl($project->id), [
        'name' => 'mailer',
        'command' => 'npm run mailer',
        'replicas' => 0,
        ...$body,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'reserved web' => [['name' => 'web'], 'name'],
    'reserved release' => [['name' => 'release'], 'name'],
    'reserved job' => [['name' => 'job'], 'name'],
    'uppercase' => [['name' => 'Queue'], 'name'],
    'starts with a digit' => [['name' => '1queue'], 'name'],
    'too long' => [['name' => str_repeat('a', 31)], 'name'],
    'duplicate' => [['name' => 'queue'], 'name'],
    'multi-line command' => [['command' => "php artisan queue:work\nrm -rf /"], 'command'],
    'empty command' => [['command' => ''], 'command'],
    'long command' => [['command' => str_repeat('a', 1001)], 'command'],
    'negative replicas' => [['replicas' => -1], 'replicas'],
    'replicas above max_replicas' => [['replicas' => 2], 'replicas'],
    'memory above the cap' => [['memory_mb' => 4096], 'memory_mb'],
]);

test('a project holds at most five definitions, and its worker replicas fit max_worker_containers', function (): void {
    ['team' => $team, 'project' => $project] = workerProject(profile: ['max_worker_containers' => 2]);

    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        addProcess($project, ['name' => "worker-{$name}", 'replicas' => 0]);
    }

    $this->actingAs($team['owner'])->postJson(processUrl($project->id), ['name' => 'sixth', 'command' => 'npm run sixth', 'replicas' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    $first = ProjectProcess::query()->where('name', 'worker-a')->sole();
    $second = ProjectProcess::query()->where('name', 'worker-b')->sole();

    $this->actingAs($team['owner'])->patchJson(processUrl($project->id, $first->id), ['replicas' => 1])->assertOk();
    $this->actingAs($team['owner'])->patchJson(processUrl($project->id, $second->id), ['replicas' => 1])->assertOk();

    $third = ProjectProcess::query()->where('name', 'worker-c')->sole();
    $this->actingAs($team['owner'])->patchJson(processUrl($project->id, $third->id), ['replicas' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('replicas');
});

test('updating and deleting a process is audited and changes the fingerprint back', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    $before = $project->fresh()->deploymentConfigFingerprint();

    $created = $this->actingAs($team['member'])->postJson(processUrl($project->id), ['name' => 'queue', 'command' => 'npm run worker'])->json('data.id');

    $this->actingAs($team['member'])->patchJson(processUrl($project->id, $created), ['command' => 'npm run worker -- --fast'])
        ->assertOk()
        ->assertJsonPath('data.command', 'npm run worker -- --fast');

    $this->actingAs($team['member'])->deleteJson(processUrl($project->id, $created))->assertNoContent();

    expect($project->fresh()->processes_fingerprint)->toBeNull()
        ->and($project->fresh()->deploymentConfigFingerprint())->toBe($before)
        ->and(AuditEvent::query()->where('action', WorkloadAudit::PROCESS_UPDATED)->sole()->metadata['to'])->toBe(['command' => 'npm run worker -- --fast'])
        ->and(AuditEvent::query()->where('action', WorkloadAudit::PROCESS_DELETED)->exists())->toBeTrue();
});

test('a process of another project is not found through this one', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    ['project' => $other] = workerProject();
    $foreign = addProcess($other);

    $this->actingAs($team['owner'])->patchJson(processUrl($project->id, $foreign->id), ['replicas' => 0])->assertNotFound();
});

test('the crash of a worker of the serving deployment shows on its process', function (): void {
    ['team' => $team, 'project' => $project] = workerProject();
    addProcess($project);
    $live = deployLive($project, $team['owner']);
    $live->update(['crashed_processes' => ['queue' => ['exit_code' => 1, 'oom_killed' => false, 'finished_at' => '2026-11-24T09:14:58Z', 'restart_count' => 4, 'running' => false, 'since' => '2026-11-24T09:15:05Z']]]);

    $this->actingAs($team['viewer'])->getJson(processUrl($project->id))
        ->assertOk()
        ->assertJsonPath('data.0.crash.exit_code', 1)
        ->assertJsonPath('data.0.crash.restart_count', 4);
});
