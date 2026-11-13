<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = adminApiAdmin();
});

test('admins list every workspace project with search and filters', function (): void {
    $owner = User::factory()->create(['name' => 'Rina']);
    $team = teamWorkspace(User::factory()->create());
    $running = Project::factory()->for($owner)->create(['name' => 'Toko Kue', 'status' => ProjectStatus::Active, 'runtime_status' => RuntimeStatus::Running]);
    Project::factory()->inWorkspace($team)->create(['name' => 'facility-reporting', 'status' => ProjectStatus::Suspended]);
    Project::factory()->create(['name' => 'Draft App']);
    Project::factory()->create(['name' => 'Deleted App'])->delete();
    Deployment::factory()->for($running)->create(['sequence' => 1, 'status' => DeploymentStatus::Succeeded]);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?search=kue')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $running->id)
        ->assertJsonPath('data.0.creator.name', 'Rina')
        ->assertJsonPath('data.0.workspace.type', 'personal')
        ->assertJsonPath('data.0.latest_deployment.status', 'succeeded');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?suspended=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'facility-reporting');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?suspended=0')->assertJsonCount(2, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?runtime_status=running')->assertJsonCount(1, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?status=draft')->assertJsonCount(1, 'data');
    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/projects?workspace_id={$team->id}")->assertJsonCount(1, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?status=gone')->assertUnprocessable();
});

test('project detail shows control requests with reasons and never environment values', function (): void {
    $project = Project::factory()->create();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'API_SECRET', 'encrypted_value' => 'super-secret-value']);

    $this->actingAs($this->admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Laporan phishing'])
        ->assertAccepted();

    $body = $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.project.status', 'suspended')
        ->assertJsonPath('data.project.suspension.reason', 'Laporan phishing')
        ->assertJsonPath('data.control_requests.0.action', 'suspend')
        ->assertJsonPath('data.control_requests.0.reason', 'Laporan phishing')
        ->assertJsonPath('data.control_requests.0.actor_id', (string) $this->admin->id)
        ->assertJsonPath('data.deployments_count', 0)
        ->getContent();

    expect($body)->not->toContain('super-secret-value')->not->toContain('API_SECRET')
        ->and(AuditEvent::query()->where('action', 'project.suspend_requested')->sole()->metadata['reason'])->toBe('Laporan phishing');
});

test('admins see and filter the projects idle sleep gave up on', function (): void {
    $node = idleSleepNode();
    [$blocked, $deployment] = idleSleepProject($node);
    idleSleepCommand($blocked, $deployment, AgentCommandStatus::Failed, [
        'failed_at' => now(),
        'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR,
    ]);
    [$retrying, $retryingDeployment] = idleSleepProject($node);
    idleSleepCommand($retrying, $retryingDeployment, AgentCommandStatus::Failed, [
        'failed_at' => now(),
        'error_code' => 'runtime_unavailable',
    ]);
    [$awake] = idleSleepProject($node);

    $flags = collect($this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects')->assertOk()->json('data'))
        ->pluck('idle_sleep_blocked', 'id')
        ->sortKeys()
        ->all();

    expect($flags)->toBe(collect([$blocked->id => true, $retrying->id => false, $awake->id => false])->sortKeys()->all());

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?idle_sleep_blocked=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $blocked->id)
        ->assertJsonPath('data.0.idle_sleep_blocked', true);
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?idle_sleep_blocked=0')->assertJsonCount(2, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects?idle_sleep_blocked=maybe')->assertUnprocessable();

    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/projects/{$blocked->id}")
        ->assertJsonPath('data.project.idle_sleep_blocked', true);
    $this->actingAs($this->admin, 'web')->getJson("/api/v1/admin/projects/{$retrying->id}")
        ->assertJsonPath('data.project.idle_sleep_blocked', false);
});

test('the idle sleep flag costs the project list one query, however long the page', function (): void {
    $node = idleSleepNode();
    $queriesFor = function (int $projects) use ($node): int {
        foreach (range(1, $projects) as $ignored) {
            [$project, $deployment] = idleSleepProject($node);
            idleSleepCommand($project, $deployment, AgentCommandStatus::Failed, ['failed_at' => now(), 'error_code' => AgentCommand::WORKLOAD_NOT_FOUND_ERROR]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/projects')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // The second call lists ten projects, the first five.
    expect($queriesFor(5))->toBe($queriesFor(5));
});

test('every project control action requires a reason', function (string $action, array $body): void {
    $project = Project::factory()->create();

    $this->actingAs($this->admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/{$action}", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'stop' => ['stop', []],
    'suspend' => ['suspend', []],
    'unsuspend' => ['unsuspend', []],
    'reconcile' => ['reconcile', ['desired_state' => 'running', 'actions' => []]],
]);
