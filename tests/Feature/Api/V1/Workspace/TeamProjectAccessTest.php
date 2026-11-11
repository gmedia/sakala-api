<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notification\UserNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: initial']],
        ], 200),
        '*' => Http::response([], 404),
    ]);

    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->viewer = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->team = teamWorkspace($this->owner, [
        [$this->member, WorkspaceRole::Member],
        [$this->viewer, WorkspaceRole::Viewer],
    ], ['name' => 'Tim Facility']);
    $this->project = Project::factory()->inWorkspace($this->team, $this->member)->create(['branch' => 'main']);
});

/** The test user for a role name; `stranger` belongs to no workspace of the project. */
function actor(object $test, string $who): User
{
    return $test->{$who};
}

dataset('readers', [
    'owner' => ['owner', true],
    'member' => ['member', true],
    'viewer' => ['viewer', true],
    'non-member' => ['stranger', false],
]);

dataset('managers', [
    'owner' => ['owner', true],
    'member' => ['member', true],
    'viewer' => ['viewer', false],
    'non-member' => ['stranger', false],
]);

test('every member reads the project; a non-member gets the generic 403', function (string $who, bool $allowed): void {
    $response = $this->actingAs(actor($this, $who), 'web')->getJson("/api/v1/app/projects/{$this->project->id}");

    if ($allowed) {
        $response->assertOk()
            ->assertJsonPath('data.workspace.id', $this->team->id)
            ->assertJsonPath('data.workspace.type', 'team')
            ->assertJsonPath('data.workspace.name', 'Tim Facility');
    } else {
        $response->assertForbidden()->assertJsonMissingPath('code');
    }
})->with('readers');

test('the project list covers the workspaces the caller belongs to', function (string $who, bool $allowed): void {
    $ids = collect($this->actingAs(actor($this, $who), 'web')->getJson('/api/v1/app/projects')->assertOk()->json('data'))->pluck('id');

    expect($ids->contains($this->project->id))->toBe($allowed);
})->with('readers');

test('deployments, events, and logs are readable by every member only', function (string $who, bool $allowed): void {
    $deployment = Deployment::factory()->for($this->project)->for($this->member, 'requester')->create(['status' => DeploymentStatus::Succeeded]);
    $base = "/api/v1/app/projects/{$this->project->id}/deployments";
    $user = actor($this, $who);

    foreach (["{$base}", "{$base}/{$deployment->id}", "{$base}/{$deployment->id}/events", "{$base}/{$deployment->id}/logs"] as $url) {
        $this->actingAs($user, 'web')->getJson($url)->assertStatus($allowed ? 200 : 403);
    }
})->with('readers');

test('owners and members change the project; viewers and non-members cannot', function (string $who, bool $allowed): void {
    $this->actingAs(actor($this, $who), 'web')
        ->patchJson("/api/v1/app/projects/{$this->project->id}", ['configured_port' => 8080])
        ->assertStatus($allowed ? 200 : 403);
})->with('managers');

test('owners and members deploy; viewers and non-members cannot', function (string $who, bool $allowed): void {
    $this->actingAs(actor($this, $who), 'web')
        ->postJson("/api/v1/app/projects/{$this->project->id}/deployments", ['branch' => 'main'])
        ->assertStatus($allowed ? 201 : 403);
})->with('managers');

test('owners and members cancel a deployment; viewers and non-members cannot', function (string $who, bool $allowed): void {
    $deployment = Deployment::factory()->for($this->project)->for($this->member, 'requester')->create(['status' => DeploymentStatus::Queued]);

    $this->actingAs(actor($this, $who), 'web')
        ->postJson("/api/v1/app/projects/{$this->project->id}/deployments/{$deployment->id}/cancel")
        ->assertStatus($allowed ? 200 : 403);
})->with('managers');

test('owners and members request an inspection; viewers and non-members cannot', function (string $who, bool $allowed): void {
    $this->actingAs(actor($this, $who), 'web')
        ->postJson("/api/v1/app/projects/{$this->project->id}/inspection")
        ->assertStatus($allowed ? 202 : 403);
})->with('managers');

test('owners and members delete the project; viewers and non-members cannot', function (string $who, bool $allowed): void {
    $this->actingAs(actor($this, $who), 'web')
        ->deleteJson("/api/v1/app/projects/{$this->project->id}")
        ->assertStatus($allowed ? 204 : 403);
})->with('managers');

test('every member lists variables, but secret values reach owners and members only', function (string $who, bool $manager): void {
    $secret = EnvironmentVariable::factory()->for($this->project)->create(['key' => 'DB_PASSWORD', 'encrypted_value' => 'hunter2', 'is_secret' => true]);
    EnvironmentVariable::factory()->for($this->project)->create(['key' => 'APP_NAME', 'encrypted_value' => 'facility', 'is_secret' => false]);
    $user = actor($this, $who);

    $list = $this->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$this->project->id}/environment-variables");

    if ($who === 'stranger') {
        $list->assertForbidden();
    } else {
        $list->assertOk();
        $rows = collect($list->json('data'))->keyBy('key');
        expect($rows['DB_PASSWORD'])->not->toHaveKey('value')
            ->and($rows['APP_NAME']['value'])->toBe('facility')
            ->and($list->getContent())->not->toContain('hunter2');
    }

    $reveal = $this->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$this->project->id}/environment-variables/{$secret->id}/value");
    $manager ? $reveal->assertOk()->assertJsonPath('data.value', 'hunter2') : $reveal->assertForbidden();

    $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$this->project->id}/environment-variables", ['key' => 'NEW_KEY', 'value' => 'v'])
        ->assertStatus($manager ? 201 : 403);
})->with('managers');

test('a member revealing a team secret is recorded as a project manager, without address', function (): void {
    $secret = EnvironmentVariable::factory()->for($this->project)->create(['is_secret' => true]);

    $this->actingAs($this->owner, 'web')
        ->getJson("/api/v1/app/projects/{$this->project->id}/environment-variables/{$secret->id}/value")
        ->assertOk();

    $audit = AuditEvent::query()->where('action', 'environment_variable.value_revealed')->firstOrFail();
    expect($audit->metadata['by_project_owner'])->toBeTrue()
        ->and($audit->ip_address)->toBeNull();
});

test('admins keep their powers over team projects', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin, 'web')->getJson("/api/v1/app/projects/{$this->project->id}")->assertOk();
    $this->actingAs($admin, 'web')->patchJson("/api/v1/app/projects/{$this->project->id}", ['configured_port' => 9000])->assertOk();
});

test('a member who leaves loses access at once while the project stays with the team', function (): void {
    $this->actingAs($this->member, 'web')->postJson("/api/v1/app/workspaces/{$this->team->id}/leave")->assertNoContent();

    $this->actingAs($this->member, 'web')->getJson("/api/v1/app/projects/{$this->project->id}")->assertForbidden();
    $this->actingAs($this->owner, 'web')->getJson("/api/v1/app/projects/{$this->project->id}")->assertOk();
    expect($this->project->refresh()->workspace_id)->toBe($this->team->id)
        ->and($this->project->user_id)->toBe($this->member->id);
});

test('owners and members create projects in a team; viewers and non-members cannot', function (string $who, bool $allowed): void {
    $response = $this->actingAs(actor($this, $who), 'web')->postJson('/api/v1/app/projects', [
        'name' => 'Facility Reporting',
        'repository_url' => 'https://github.com/gmedia/facility-reporting',
        'branch' => 'main',
        'workspace_id' => $this->team->id,
    ]);

    if ($allowed) {
        $response->assertCreated()->assertJsonPath('data.workspace.id', $this->team->id);
        expect(Project::query()->findOrFail($response->json('data.id'))->user_id)->toBe(actor($this, $who)->id);
    } else {
        $response->assertForbidden();
    }
})->with('managers');

test('without a workspace the project goes to the personal workspace, as before', function (): void {
    $response = $this->actingAs($this->member, 'web')->postJson('/api/v1/app/projects', [
        'name' => 'Tugas Pribadi',
        'repository_url' => 'https://github.com/rani/tugas',
        'branch' => 'main',
    ]);

    $personal = $this->member->personalWorkspace()->firstOrFail();

    $response->assertCreated()
        ->assertJsonPath('data.workspace.id', $personal->id)
        ->assertJsonPath('data.workspace.type', 'personal');
});

test('an unknown or malformed workspace is a validation error', function (): void {
    foreach (['not-a-uuid', (string) Str::uuid7()] as $workspaceId) {
        $this->actingAs($this->member, 'web')->postJson('/api/v1/app/projects', [
            'name' => 'X',
            'repository_url' => 'https://github.com/rani/x',
            'branch' => 'main',
            'workspace_id' => $workspaceId,
        ])->assertUnprocessable()->assertJsonValidationErrors('workspace_id');
    }
});

test('the project list filters by workspace, and refuses one the caller does not belong to', function (): void {
    $personalProject = Project::factory()->for($this->member)->create();

    $all = collect($this->actingAs($this->member, 'web')->getJson('/api/v1/app/projects')->json('data'))->pluck('id');
    expect($all->all())->toContain($personalProject->id, $this->project->id);

    $teamOnly = collect($this->actingAs($this->member, 'web')->getJson("/api/v1/app/projects?workspace_id={$this->team->id}")->assertOk()->json('data'))->pluck('id');
    expect($teamOnly->all())->toBe([$this->project->id]);

    $this->actingAs($this->stranger, 'web')->getJson("/api/v1/app/projects?workspace_id={$this->team->id}")->assertForbidden();
    $this->actingAs($this->member, 'web')->getJson('/api/v1/app/projects?workspace_id=nope')->assertUnprocessable();
});

test('deployment broadcasts are authorised through membership', function (): void {
    $deployment = Deployment::factory()->for($this->project)->for($this->member, 'requester')->create();
    $channel = Broadcast::driver()->getChannels()->get('deployment.{deploymentId}');

    expect($channel($this->viewer, $deployment->id))->toBeTrue()
        ->and($channel($this->stranger, $deployment->id))->toBeFalse();
});

test('project news reaches owners and members, and deployment news reaches its requester while they can act', function (): void {
    $notifier = app(UserNotifier::class);
    $category = NotificationCategory::ProjectIssue;

    $notifier->sendToProject($this->project, $category, 'Aplikasi berhenti', 'pesan');

    expect($this->owner->notifications()->count())->toBe(1)
        ->and($this->member->notifications()->count())->toBe(1)
        ->and($this->viewer->notifications()->count())->toBe(0)
        ->and($this->stranger->notifications()->count())->toBe(0);

    $notifier->sendToProject($this->project, NotificationCategory::DeploymentSuccess, 'Berhasil', 'pesan', preferred: $this->member);

    expect($this->member->notifications()->count())->toBe(2)
        ->and($this->owner->notifications()->count())->toBe(1);

    // A requester who is now only a viewer is no longer the one to tell.
    $notifier->sendToProject($this->project, NotificationCategory::DeploymentFailure, 'Gagal', 'pesan', preferred: $this->viewer);

    expect($this->viewer->notifications()->count())->toBe(0)
        ->and($this->owner->notifications()->count())->toBe(2)
        ->and($this->member->notifications()->count())->toBe(3);
});

test('a public repository keeps deploying after its creator leaves the team', function (): void {
    $this->actingAs($this->member, 'web')->postJson("/api/v1/app/workspaces/{$this->team->id}/leave")->assertNoContent();

    $this->actingAs($this->owner, 'web')
        ->postJson("/api/v1/app/projects/{$this->project->id}/deployments", ['branch' => 'main'])
        ->assertCreated();
});
