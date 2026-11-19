<?php

declare(strict_types=1);

use App\Data\Notification\ProjectAlertMailData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\GithubInstallationStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectAlert;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\WorkspaceRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\GithubInstallation;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\ProjectAlertMailWindow;
use App\Models\User;
use App\Notifications\Project\ProjectAlertMailNotification;
use App\Notifications\UserAlertNotification;
use App\Services\Notification\NotificationUnsubscribeLinks;
use App\Services\Notification\UserNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

const ALERT_TOKEN = 'alert-mail-token';

beforeEach(function (): void {
    config([
        'sakala.email_delivery_enabled' => true,
        'sakala.notifications.email_coalesce_minutes' => 30,
        'sakala.console_url' => 'https://console.example.test',
    ]);
    Notification::fake();
});

function alertNode(): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'token_hash' => hash_hmac('sha256', ALERT_TOKEN, (string) config('app.key')),
    ]);
}

/** @return array{Deployment, AgentCommand} A building deployment of the project, requested by $requester. */
function alertAttempt(Project $project, AgentNode $node, ?User $requester = null, int $sequence = 1): array
{
    $deployment = Deployment::factory()->for($project)->create([
        'agent_node_id' => $node->id,
        'status' => DeploymentStatus::Building,
        'sequence' => $sequence,
        'commit_sha' => str_repeat('a', 40),
        ...($requester === null ? [] : ['requested_by' => $requester->id]),
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Running,
    ]);

    return [$deployment, $command];
}

function failAlertAttempt(object $test, AgentNode $node, AgentCommand $command, string $code = 'runtime_build_failed'): void
{
    $test->withHeaders(['Authorization' => 'Bearer '.ALERT_TOKEN, 'X-Agent-Id' => $node->agent_id])
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", ['error_code' => $code, 'error_message' => 'npm ERR! secret=hunter2'])
        ->assertNoContent();
}

function completeAlertAttempt(object $test, AgentNode $node, AgentCommand $command): void
{
    $test->withHeaders(['Authorization' => 'Bearer '.ALERT_TOKEN, 'X-Agent-Id' => $node->agent_id])
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])
        ->assertNoContent();
}

/** @return list<ProjectAlertMailNotification> */
function alertMailsTo(User $user): array
{
    return Notification::sent($user, ProjectAlertMailNotification::class)->values()->all();
}

test('email preferences default to on for failures and problems, off for successes, independent of the bell', function (): void {
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
            'email' => [
                'deployment_success' => false,
                'deployment_failure' => true,
                'project_issue' => true,
            ],
        ]]);

    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['email' => ['deployment_success' => true]])
        ->assertOk()
        ->assertJsonPath('data.email.deployment_success', true)
        ->assertJsonPath('data.email.deployment_failure', true)
        ->assertJsonPath('data.deployment_success', true);

    // Turning the bell off leaves email alone, and the other way around.
    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['deployment_failure' => false])
        ->assertOk()
        ->assertJsonPath('data.deployment_failure', false)
        ->assertJsonPath('data.email.deployment_failure', true);

    $this->patchJson(route('api.v1.app.notification-preferences.update'), ['email' => ['project_issue' => false], 'workspace' => false])
        ->assertOk()
        ->assertJsonPath('data.email.project_issue', false)
        ->assertJsonPath('data.email.deployment_success', true)
        ->assertJsonPath('data.workspace', false);

    expect($user->fresh()->notification_email_preferences)->toBe([
        'deployment_success' => true,
        'deployment_failure' => true,
        'project_issue' => false,
    ]);
});

test('email preferences reject categories without email, and values that are not booleans', function (array $payload, string $error): void {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->patchJson(route('api.v1.app.notification-preferences.update'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);

    expect($user->fresh()->notification_email_preferences)->toBeNull();
})->with([
    'workspace has no email' => [['email' => ['workspace' => true]], 'email'],
    'account security has no email' => [['email' => ['account_security' => false]], 'email'],
    'not a boolean' => [['email' => ['deployment_failure' => 'maybe']], 'email.deployment_failure'],
    'not an object' => [['email' => true], 'email'],
    'null' => [['email' => null], 'email'],
]);

test('a failed deployment emails its requester with the stage, the safe summary, and a link to the deployment', function (): void {
    $node = alertNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['name' => 'toko-kopi-web']);
    [$deployment, $command] = alertAttempt($project, $node, $user, sequence: 5);

    failAlertAttempt($this, $node, $command);

    $mails = alertMailsTo($user);
    expect($mails)->toHaveCount(1);

    $mail = $mails[0];
    expect($mail->data->alert)->toBe(ProjectAlert::DeploymentFailed)
        ->and($mail->data->deploymentId)->toBe($deployment->id)
        ->and($mail->data->deploymentSequence)->toBe(5)
        ->and($mail->data->failureStage)->toBe(DeploymentStatus::Building)
        ->and($mail->data->summary)->toBe($deployment->fresh()->failure_summary)
        ->and($mail->heldBack)->toBe(0);

    $rendered = $mail->toMail($user);
    $text = implode("\n", [$rendered->subject, ...$rendered->introLines, ...$rendered->outroLines]);

    expect($rendered->subject)->toBe('Deployment toko-kopi-web di Sakala gagal di tahap Build proyek')
        ->and($rendered->actionUrl)->toBe("https://console.example.test/projects/{$project->id}/deployments/{$deployment->id}")
        ->and($text)->toContain('Salin ringkasan error')
        // The agent's raw error never reaches an inbox, only the classifier's summary.
        ->and($text)->not->toContain('hunter2')
        ->and($text)->not->toContain('npm ERR');

    Notification::assertSentTo($user, UserAlertNotification::class);
});

test('alert emails go through the queue', function (): void {
    Notification::swap(new ChannelManager(app()));
    Queue::fake();
    $node = alertNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    [, $command] = alertAttempt($project, $node, $user);

    failAlertAttempt($this, $node, $command);

    expect(new ProjectAlertMailNotification(new ProjectAlertMailData(ProjectAlert::DeploymentFailed, $project->id, $project->name)))
        ->toBeInstanceOf(ShouldQueue::class);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof ProjectAlertMailNotification
        && $job->channels === ['mail']);
});

test('no email when the category email is off, while the bell still rings', function (): void {
    $node = alertNode();
    $user = User::factory()->create(['notification_email_preferences' => ['deployment_failure' => false]]);
    $project = Project::factory()->for($user)->create();
    [, $command] = alertAttempt($project, $node, $user);

    failAlertAttempt($this, $node, $command);

    Notification::assertNotSentTo($user, ProjectAlertMailNotification::class);
    Notification::assertSentTo($user, UserAlertNotification::class);
});

test('email still goes out with the bell turned off for the category', function (): void {
    $node = alertNode();
    $user = User::factory()->create(['notification_preferences' => ['deployment_failure' => false]]);
    $project = Project::factory()->for($user)->create();
    [, $command] = alertAttempt($project, $node, $user);

    failAlertAttempt($this, $node, $command);

    Notification::assertSentTo($user, ProjectAlertMailNotification::class);
    Notification::assertNotSentTo($user, UserAlertNotification::class);
});

test('a success is emailed only to someone who turned that email on', function (): void {
    $node = alertNode();
    $quiet = User::factory()->create();
    $loud = User::factory()->create(['notification_email_preferences' => ['deployment_success' => true]]);
    [, $quietCommand] = alertAttempt(Project::factory()->for($quiet)->create(), $node, $quiet, sequence: 1);
    [, $loudCommand] = alertAttempt(Project::factory()->for($loud)->create(), $node, $loud, sequence: 1);

    completeAlertAttempt($this, $node, $quietCommand);
    completeAlertAttempt($this, $node, $loudCommand);

    Notification::assertNotSentTo($quiet, ProjectAlertMailNotification::class);
    expect(alertMailsTo($loud))->toHaveCount(1)
        ->and(alertMailsTo($loud)[0]->data->alert)->toBe(ProjectAlert::DeploymentSucceeded);
});

test('repeated failures of one project become one email per window, and the next says how many were held back', function (): void {
    $node = alertNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    foreach ([1, 2, 3] as $sequence) {
        [, $command] = alertAttempt($project, $node, $user, $sequence);
        failAlertAttempt($this, $node, $command);
        $this->travel(5)->minutes();
    }

    expect(alertMailsTo($user))->toHaveCount(1)
        ->and(ProjectAlertMailWindow::query()->sole()->suppressed_count)->toBe(2);

    // 30 minutes after the first email, the window is over.
    $this->travel(16)->minutes();
    [, $command] = alertAttempt($project, $node, $user, 4);
    failAlertAttempt($this, $node, $command);

    $mails = alertMailsTo($user);
    expect($mails)->toHaveCount(2)
        ->and($mails[1]->heldBack)->toBe(2)
        ->and(implode("\n", $mails[1]->toMail($user)->introLines))->toContain('Ada 2 kegagalan lagi di proyek ini sejak email terakhir.')
        ->and(ProjectAlertMailWindow::query()->sole()->suppressed_count)->toBe(0);

    // Every failure still reached the bell.
    expect(Notification::sent($user, UserAlertNotification::class))->toHaveCount(4);
});

test('the window is per project and per kind of alert, so a crash is never held back by a failure', function (): void {
    $node = alertNode();
    $user = User::factory()->create();
    $first = Project::factory()->for($user)->create(['status' => ProjectStatus::Active, 'runtime_status' => RuntimeStatus::Running]);
    $second = Project::factory()->for($user)->create();

    [, $command] = alertAttempt($first, $node, $user, 1);
    failAlertAttempt($this, $node, $command);
    [, $command] = alertAttempt($second, $node, $user, 1);
    failAlertAttempt($this, $node, $command);

    $live = Deployment::factory()->for($first)->create(['agent_node_id' => $node->id, 'status' => DeploymentStatus::Succeeded, 'sequence' => 2, 'requested_by' => $user->id]);
    $first->update(['active_deployment_id' => $live->id, 'runtime_status' => RuntimeStatus::Running]);
    $payload = heartbeatPayload();
    $payload['metadata']['workloads']['crashed_details'] = [[
        'project_id' => $first->id,
        'deployment_id' => $live->id,
        'exit_code' => 1,
        'oom_killed' => false,
        'finished_at' => '2026-11-05T08:00:00Z',
    ]];
    $payload['metadata']['detail_counts']['crashed_details'] = 1;
    $this->withHeaders(['Authorization' => 'Bearer '.ALERT_TOKEN, 'X-Agent-Id' => $node->agent_id])
        ->postJson('/api/agent/v1/heartbeat', $payload)
        ->assertOk();

    $alerts = array_map(fn (ProjectAlertMailNotification $mail): string => $mail->data->projectId.':'.$mail->data->alert->value, alertMailsTo($user));

    expect($alerts)->toEqualCanonicalizing([
        "{$first->id}:deployment_failed",
        "{$second->id}:deployment_failed",
        "{$first->id}:app_crashed",
    ]);
});

test('a coalescing window of 0 sends every alert', function (): void {
    config(['sakala.notifications.email_coalesce_minutes' => 0]);
    $node = alertNode();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    foreach ([1, 2] as $sequence) {
        [, $command] = alertAttempt($project, $node, $user, $sequence);
        failAlertAttempt($this, $node, $command);
    }

    expect(alertMailsTo($user))->toHaveCount(2);
});

test('on a team, the requester and the owners get the email; other members and viewers do not', function (): void {
    $node = alertNode();
    $owner = User::factory()->create();
    $coOwner = User::factory()->create();
    $requester = User::factory()->create();
    $otherMember = User::factory()->create();
    $viewer = User::factory()->create();
    $team = teamWorkspace($owner, [
        [$coOwner, WorkspaceRole::Owner],
        [$requester, WorkspaceRole::Member],
        [$otherMember, WorkspaceRole::Member],
        [$viewer, WorkspaceRole::Viewer],
    ]);
    $project = Project::factory()->inWorkspace($team, $requester)->create();
    [, $command] = alertAttempt($project, $node, $requester);

    failAlertAttempt($this, $node, $command);

    foreach ([$owner, $coOwner, $requester] as $user) {
        expect(alertMailsTo($user))->toHaveCount(1);
    }

    Notification::assertNotSentTo($otherMember, ProjectAlertMailNotification::class);
    Notification::assertNotSentTo($viewer, ProjectAlertMailNotification::class);
});

test('a requester who is only a viewer now is not emailed; the owners still are', function (): void {
    $node = alertNode();
    $owner = User::factory()->create();
    $demoted = User::factory()->create();
    $team = teamWorkspace($owner, [[$demoted, WorkspaceRole::Viewer]]);
    $project = Project::factory()->inWorkspace($team, $owner)->create();
    [, $command] = alertAttempt($project, $node, $demoted);

    failAlertAttempt($this, $node, $command);

    expect(alertMailsTo($owner))->toHaveCount(1);
    Notification::assertNotSentTo($demoted, ProjectAlertMailNotification::class);
});

test('an owner who turned the email off is skipped, the others are not', function (): void {
    $node = alertNode();
    $owner = User::factory()->create(['notification_email_preferences' => ['deployment_failure' => false]]);
    $requester = User::factory()->create();
    $team = teamWorkspace($owner, [[$requester, WorkspaceRole::Member]]);
    $project = Project::factory()->inWorkspace($team, $requester)->create();
    [, $command] = alertAttempt($project, $node, $requester);

    failAlertAttempt($this, $node, $command);

    Notification::assertNotSentTo($owner, ProjectAlertMailNotification::class);
    expect(alertMailsTo($requester))->toHaveCount(1);
});

test('nothing is emailed to an unverified address, or with email delivery off', function (): void {
    $node = alertNode();
    $unverified = User::factory()->unverified()->create();
    [, $command] = alertAttempt(Project::factory()->for($unverified)->create(), $node, $unverified);
    failAlertAttempt($this, $node, $command);

    config(['sakala.email_delivery_enabled' => false]);
    $verified = User::factory()->create();
    [, $command] = alertAttempt(Project::factory()->for($verified)->create(), $node, $verified);
    failAlertAttempt($this, $node, $command);

    Notification::assertNotSentTo($unverified, ProjectAlertMailNotification::class);
    Notification::assertNotSentTo($verified, ProjectAlertMailNotification::class);
    expect(ProjectAlertMailWindow::query()->count())->toBe(0);
});

test('a rolled back transaction emails nobody', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    try {
        DB::transaction(function () use ($project, $user): void {
            app(UserNotifier::class)->emailProject($project, new ProjectAlertMailData(ProjectAlert::DeploymentFailed, $project->id, $project->name), $user);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    Notification::assertNotSentTo($user, ProjectAlertMailNotification::class);
    expect(ProjectAlertMailWindow::query()->count())->toBe(0);
});

test('a skipped push is emailed to the owner with the reason', function (): void {
    config()->set('services.github_app.webhook_secret', 'webhook-secret');
    Http::fake(['api.github.com/repositories/777' => Http::response(['id' => 777])]);
    $owner = User::factory()->create();
    OAuthAccount::factory()->for($owner)->create(['provider' => 'github', 'access_token' => 'owner-token']);
    $project = Project::factory()->for($owner)->create([
        'status' => ProjectStatus::Suspended,
        'auto_deploy' => true,
        'github_repository_id' => 777,
        'branch' => 'main',
        'github_installation_id' => GithubInstallation::query()->create([
            'github_installation_id' => 42,
            'account_id' => 100,
            'account_login' => 'sakala',
            'account_type' => 'Organization',
            'repository_selection' => 'selected',
            'permissions' => ['contents' => 'read'],
            'status' => GithubInstallationStatus::Active,
        ])->id,
    ]);
    $body = json_encode([
        'ref' => 'refs/heads/main',
        'after' => str_repeat('b', 40),
        'deleted' => false,
        'repository' => ['id' => 777],
        'installation' => ['id' => 42],
        'head_commit' => ['message' => 'fix: login'],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', route('api.v1.webhooks.github'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_GITHUB_DELIVERY' => 'a47ac10b-58cc-4372-a567-0e02b2c3d479',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'webhook-secret'),
    ], $body)->assertOk();

    $mails = alertMailsTo($owner);
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->data->alert)->toBe(ProjectAlert::AutoDeploySkipped)
        ->and($mails[0]->data->summary)->toBe('Proyek ini sedang ditangguhkan, jadi push terbaru tidak di-deploy.')
        ->and($mails[0]->toMail($owner)->actionUrl)->toBe("https://console.example.test/projects/{$project->id}");
});

test('the signed unsubscribe link turns that category email off without a session, and only that one', function (): void {
    $user = User::factory()->create();
    $url = app(NotificationUnsubscribeLinks::class)->oneClick($user->id, NotificationCategory::DeploymentFailure);

    // RFC 8058: the mail client POSTs this body to the link.
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])
        ->assertOk()
        ->assertExactJson(['data' => ['category' => 'deployment_failure', 'email' => false]]);

    $preferences = $user->fresh()->notificationPreferences();
    expect($preferences->email)->toBe(['deployment_success' => false, 'deployment_failure' => false, 'project_issue' => true])
        ->and($preferences->preferences['deployment_failure'])->toBeTrue();

    // Again: still off, still fine.
    $this->postJson($url)->assertOk();
    expect($user->fresh()->wantsNotificationEmail(NotificationCategory::DeploymentFailure))->toBeFalse();
});

test('an unsigned, tampered, or foreign unsubscribe link changes nothing', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $url = app(NotificationUnsubscribeLinks::class)->oneClick($user->id, NotificationCategory::ProjectIssue);

    $this->postJson(route(NotificationUnsubscribeLinks::ROUTE, ['user' => $user->id, 'category' => 'project_issue']))->assertForbidden();
    $this->postJson(str_replace("user={$user->id}", "user={$other->id}", $url))->assertForbidden();
    $this->postJson(str_replace('category=project_issue', 'category=deployment_failure', $url))->assertForbidden();

    expect($user->fresh()->notification_email_preferences)->toBeNull()
        ->and($other->fresh()->notification_email_preferences)->toBeNull();
});

test('a signed link for a category without email, or for a deleted account, is refused', function (): void {
    $user = User::factory()->create();
    $workspaceLink = URL::signedRoute(NotificationUnsubscribeLinks::ROUTE, ['category' => 'workspace', 'user' => $user->id]);
    $goneLink = app(NotificationUnsubscribeLinks::class)->oneClick(999_999, NotificationCategory::DeploymentFailure);

    $this->postJson($workspaceLink)->assertUnprocessable()->assertJsonValidationErrors(['category']);
    $this->postJson($goneLink)->assertNotFound();
});

test('opening the unsubscribe link only forwards to the console page that asks first', function (): void {
    $user = User::factory()->create();
    $url = app(NotificationUnsubscribeLinks::class)->oneClick($user->id, NotificationCategory::DeploymentFailure);
    $query = (string) parse_url($url, PHP_URL_QUERY);

    $this->get($url)->assertRedirect("https://console.example.test/notifications/unsubscribe?{$query}");

    expect($user->fresh()->notification_email_preferences)->toBeNull()
        ->and(app(NotificationUnsubscribeLinks::class)->confirmPage($url))->toBe("https://console.example.test/notifications/unsubscribe?{$query}");
});

test('the unsubscribe link is rate limited per address', function (): void {
    config(['sakala.rate_limits.notification_unsubscribe' => 2]);
    $user = User::factory()->create();
    $url = app(NotificationUnsubscribeLinks::class)->oneClick($user->id, NotificationCategory::DeploymentFailure);

    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertTooManyRequests();
});
