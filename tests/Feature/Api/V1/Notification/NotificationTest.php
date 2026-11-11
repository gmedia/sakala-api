<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Notifications\UserAlertNotification;
use App\Services\Notification\UserNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function notificationNode(string $token = 'notify-token'): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

function notificationHeaders(AgentNode $node, string $token = 'notify-token'): array
{
    return ['Authorization' => 'Bearer '.$token, 'X-Agent-Id' => $node->agent_id];
}

/** @return array{Project, Deployment, AgentCommand} */
function notificationAttempt(User $user, AgentNode $node, array $projectAttributes = []): array
{
    $project = Project::factory()->for($user)->create($projectAttributes);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => 1,
        'commit_sha' => str_repeat('a', 40),
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    return [$project, $deployment, $command];
}

test('a successful deployment notifies the owner', function (): void {
    $node = notificationNode();
    $user = User::factory()->create();
    [$project, $deployment, $command] = notificationAttempt($user, $node, ['name' => 'Portfolio']);

    $this->withHeaders(notificationHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    $notification = $user->notifications()->sole();

    expect($notification->data)->toMatchArray([
        'category' => 'deployment_success',
        'title' => 'Deployment berhasil',
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
    ])->and($notification->data['message'])->toContain('Portfolio')->toContain('aaaaaaa');
});

test('a failed deployment notifies the owner with the summary', function (): void {
    $node = notificationNode();
    $user = User::factory()->create();
    [, , $command] = notificationAttempt($user, $node);

    $this->withHeaders(notificationHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => 'runtime_build_failed', 'error_message' => 'x'])
        ->assertNoContent();

    expect($user->notifications()->sole()->data)->toMatchArray([
        'category' => 'deployment_failure',
        'title' => 'Deployment gagal',
    ]);
});

test('a repeated completion does not notify twice', function (): void {
    $node = notificationNode();
    $user = User::factory()->create();
    [, , $command] = notificationAttempt($user, $node);

    foreach ([1, 2] as $ignored) {
        $this->withHeaders(notificationHeaders($node))
            ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
            ->assertNoContent();
    }

    expect($user->notifications()->count())->toBe(1);
});

test('a crash notifies the owner once, however many heartbeats repeat it', function (): void {
    $node = notificationNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'status' => ProjectStatus::Active,
        'runtime_status' => RuntimeStatus::Running,
    ]);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    $payload = heartbeatPayload();
    $payload['metadata']['workloads']['crashed_details'] = [[
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'exit_code' => 1,
        'oom_killed' => false,
        'finished_at' => '2026-11-05T08:00:00Z',
    ]];
    $payload['metadata']['detail_counts']['crashed_details'] = 1;

    foreach ([1, 2] as $ignored) {
        $this->withHeaders(notificationHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();
    }

    expect($user->notifications()->sole()->data['category'])->toBe('project_issue');
});

test('recovery sends nothing, and only a new crash notifies again', function (): void {
    $node = notificationNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['status' => ProjectStatus::Active, 'runtime_status' => RuntimeStatus::Running]);
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Succeeded,
        'sequence' => 1,
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);

    $heartbeat = function (array $details) use ($node): void {
        $payload = heartbeatPayload();
        $payload['metadata']['workloads']['crashed_details'] = $details;
        $payload['metadata']['detail_counts']['crashed_details'] = count($details);

        $this->withHeaders(notificationHeaders($node))->postJson('/api/agent/v1/heartbeat', $payload)->assertOk();
    };
    $crash = [[
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'exit_code' => 1,
        'oom_killed' => false,
        'finished_at' => '2026-11-05T08:00:00Z',
    ]];

    // A one-off restart the agent never reports leaves everything quiet.
    $heartbeat([]);
    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($user->notifications()->count())->toBe(0);

    $heartbeat($crash);
    $heartbeat($crash);
    expect($user->notifications()->count())->toBe(1);

    $heartbeat([]);
    expect($project->fresh()->runtime_status)->toBe(RuntimeStatus::Running)
        ->and($user->notifications()->count())->toBe(1);

    $heartbeat($crash);
    expect($user->notifications()->count())->toBe(2);
});

test('a password change notifies under account and security', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->putJson(route('api.v1.auth.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
        ->assertOk();

    expect($user->notifications()->sole()->data['category'])->toBe('account_security');
});

test('a category turned off creates nothing', function (): void {
    $node = notificationNode();
    $user = User::factory()->create(['notification_preferences' => ['deployment_success' => false]]);
    [, , $command] = notificationAttempt($user, $node);

    $this->withHeaders(notificationHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();

    expect($user->notifications()->count())->toBe(0);
});

test('a rolled back transaction leaves no notification', function (): void {
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user): void {
            app(UserNotifier::class)->send($user, NotificationCategory::AccountSecurity, 'Judul', 'Pesan');

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect($user->notifications()->count())->toBe(0);
});

test('a new notification is broadcast on the user channel', function (): void {
    Event::fake([BroadcastNotificationCreated::class]);
    $user = User::factory()->create();

    app(UserNotifier::class)->send($user, NotificationCategory::AccountSecurity, 'Judul', 'Pesan');

    Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($user): bool {
        return $event->broadcastOn()[0]->name === 'private-users.'.$user->id
            && $event->broadcastAs() === 'notification.created'
            && $event->broadcastWith()['category'] === 'account_security'
            && $event->broadcastWith()['title'] === 'Judul';
    });
});

test('the list is paginated, newest first, and only shows your own', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $other->notify(new UserAlertNotification(NotificationCategory::ProjectIssue, 'Punya orang', 'x'));

    foreach (range(1, 3) as $index) {
        $user->notify(new UserAlertNotification(NotificationCategory::ProjectIssue, "Judul {$index}", 'Pesan'));
    }

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.notifications.index', ['per_page' => 2]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonStructure(['data' => [['id', 'category', 'title', 'message', 'project_id', 'deployment_id', 'read_at', 'created_at']]]);

    $this->getJson(route('api.v1.app.notifications.index', ['per_page' => 51]))
        ->assertUnprocessable();
});

test('unread count, mark one, and mark all stay consistent', function (): void {
    $user = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $user->notify(new UserAlertNotification(NotificationCategory::ProjectIssue, 'Judul', 'Pesan'));
    }

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.notifications.unread-count'))
        ->assertOk()
        ->assertJsonPath('data.count', 3);

    $id = $user->notifications()->firstOrFail()->id;

    $first = $this->postJson(route('api.v1.app.notifications.read', $id))
        ->assertOk()
        ->json('data.read_at');

    expect($first)->not->toBeNull();

    // Repeating keeps the first timestamp.
    $this->postJson(route('api.v1.app.notifications.read', $id))
        ->assertOk()
        ->assertJsonPath('data.read_at', $first);

    $this->getJson(route('api.v1.app.notifications.unread-count'))->assertJsonPath('data.count', 2);

    $this->getJson(route('api.v1.app.notifications.index', ['unread' => 1]))->assertJsonCount(2, 'data');

    $this->postJson(route('api.v1.app.notifications.read-all'))->assertNoContent();

    $this->getJson(route('api.v1.app.notifications.unread-count'))->assertJsonPath('data.count', 0);
});

test('nobody can read or mark another user notification', function (): void {
    $owner = User::factory()->create();
    $owner->notify(new UserAlertNotification(NotificationCategory::ProjectIssue, 'Judul', 'Pesan'));
    $id = $owner->notifications()->firstOrFail()->id;

    $this->postJson(route('api.v1.app.notifications.read', $id))->assertUnauthorized();
    $this->getJson(route('api.v1.app.notifications.index'))->assertUnauthorized();

    $this->actingAs(User::factory()->create(), 'web')
        ->postJson(route('api.v1.app.notifications.read', $id))
        ->assertNotFound();

    expect($owner->notifications()->firstOrFail()->read_at)->toBeNull();
});

test('preferences default to on and update per category', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.app.notification-preferences.show'))
        ->assertOk()
        ->assertExactJson(['data' => [
            'deployment_success' => true,
            'deployment_failure' => true,
            'project_issue' => true,
            'account_security' => true,
            'workspace' => true,
        ]]);

    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['workspace' => false])
        ->assertOk()
        ->assertJsonPath('data.workspace', false)
        ->assertJsonPath('data.account_security', true);

    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['deployment_success' => false])
        ->assertOk()
        ->assertJsonPath('data.deployment_success', false)
        ->assertJsonPath('data.deployment_failure', true);

    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['project_issue' => false])
        ->assertOk()
        ->assertJsonPath('data.deployment_success', false)
        ->assertJsonPath('data.project_issue', false);

    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['project_issue' => 'maybe'])
        ->assertUnprocessable();
});

test('preferences require a session', function (): void {
    $this->getJson(route('api.v1.app.notification-preferences.show'))->assertUnauthorized();
    $this->patchJson(route('api.v1.app.notification-preferences.update'), [])->assertUnauthorized();
});

test('old notifications are pruned and recent ones stay', function (): void {
    $user = User::factory()->create();
    $user->notify(new UserAlertNotification(NotificationCategory::ProjectIssue, 'Lama', 'x'));
    $user->notify(new UserAlertNotification(NotificationCategory::ProjectIssue, 'Baru', 'x'));
    $old = $user->notifications()->get()->firstWhere(fn ($notification): bool => $notification->data['title'] === 'Lama');
    DB::table('notifications')->where('id', $old->id)->update(['created_at' => now()->subDays(91)]);

    $this->artisan('notifications:prune', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY RUN] 1 notifications')
        ->assertSuccessful();

    expect($user->notifications()->count())->toBe(2);

    $this->artisan('notifications:prune')
        ->expectsOutputToContain('Pruned 1 notifications (retention: 90 days)')
        ->assertSuccessful();

    expect($user->notifications()->sole()->data['title'])->toBe('Baru');
});
