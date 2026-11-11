<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use App\Notifications\Project\ProjectSuspendedNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

function suspendAs(object $test, User $admin, Project $project, array $headers = []): object
{
    return $test->actingAs($admin, 'web')
        ->withHeaders($headers)
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Halaman phishing yang meniru bank.']);
}

function inAppNotices(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->pluck('data')
        ->map(fn (string $data): array => json_decode($data, true))->all();
}

test('the owner of a suspended project is told why and who to write to', function (): void {
    config()->set('sakala.email_delivery_enabled', true);
    Notification::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['name' => 'toko-saya']);

    suspendAs($this, User::factory()->create(['role' => UserRole::Admin]), $project)->assertAccepted();

    Notification::assertSentTo($owner, ProjectSuspendedNotification::class, function (ProjectSuspendedNotification $notification) use ($owner, $project): bool {
        $mail = $notification->toMail($owner);

        return $mail instanceof MailMessage
            && str_contains(implode(' ', $mail->introLines), 'Halaman phishing yang meniru bank.')
            && str_contains(implode(' ', $mail->introLines), 'abuse@sakala.dev')
            && $notification->projectId === $project->id
            && str_contains((string) $mail->subject, 'toko-saya');
    });
});

test('the in-app notice carries the reason and the contact, even with the category switched off', function (): void {
    $owner = User::factory()->create([
        'notification_preferences' => [NotificationCategory::ProjectIssue->value => false],
    ]);
    $project = Project::factory()->for($owner)->create(['name' => 'toko-saya']);

    suspendAs($this, User::factory()->create(['role' => UserRole::Admin]), $project)->assertAccepted();

    $notices = inAppNotices($owner);

    expect($notices)->toHaveCount(1)
        ->and($notices[0]['category'])->toBe('project_issue')
        ->and($notices[0]['project_id'])->toBe($project->id)
        ->and($notices[0]['message'])->toContain('Halaman phishing yang meniru bank.')
        ->and($notices[0]['message'])->toContain('abuse@sakala.dev');
});

test('without email delivery the in-app notice still goes', function (): void {
    config()->set('sakala.email_delivery_enabled', false);
    Notification::fake();
    $owner = User::factory()->create();

    suspendAs($this, User::factory()->create(['role' => UserRole::Admin]), Project::factory()->for($owner)->create())->assertAccepted();

    Notification::assertNotSentTo($owner, ProjectSuspendedNotification::class);
});

test('retrying the same suspend tells the owner only once', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $key = ['Idempotency-Key' => '018f6d3a-0000-7000-8000-000000000001'];

    suspendAs($this, $admin, $project, $key)->assertAccepted();
    suspendAs($this, $admin, $project, $key)->assertAccepted();

    expect(inAppNotices($owner))->toHaveCount(1);
});

test('the deploy refusal names the abuse contact', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Suspended]);

    $this->actingAs($owner, 'web')
        ->postJson(route('projects.deployments.store', $project), ['branch' => 'main'])
        ->assertConflict()
        ->assertJsonPath('code', 'PROJECT_SUSPENDED')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'abuse@sakala.dev'));
});

// ─── Unsuspend ───────────────────────────────────────────────────────────────

test('an admin can lift a suspension, and the owner is told', function (bool $hasDeployed, ProjectStatus $expected): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Suspended]);

    if ($hasDeployed) {
        Deployment::factory()->for($project)->create(['status' => DeploymentStatus::Succeeded]);
    }

    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima.'])
        ->assertAccepted()
        ->assertJsonPath('data.status', $expected->value)
        ->assertJsonPath('data.command', null);

    expect($project->fresh()->status)->toBe($expected)
        ->and(AgentCommand::query()->where('project_id', $project->id)->count())->toBe(0)
        ->and(inAppNotices($owner)[0]['title'])->toBe('Proyek dibuka kembali');

    $audit = AuditEvent::query()->where('action', 'project.unsuspended')->sole();

    expect($audit->subject_id)->toBe($project->id)
        ->and((string) $audit->actor_id)->toBe((string) $admin->id)
        ->and($audit->metadata['reason'])->toBe('Banding diterima.');
})->with([
    'deployed before' => [true, ProjectStatus::Active],
    'never deployed' => [false, ProjectStatus::Draft],
]);

test('after a suspension is lifted the owner can deploy again', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Suspended]);

    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima.'])
        ->assertAccepted();

    expect($owner->can('deploy', $project->fresh()))->toBeTrue();
});

test('only an admin can lift a suspension', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Suspended]);

    $this->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'x'])->assertUnauthorized();

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'x'])
        ->assertForbidden();

    expect($project->fresh()->status)->toBe(ProjectStatus::Suspended);
});

test('lifting needs a reason, a suspended project, and a finished suspend command', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $active = Project::factory()->create(['status' => ProjectStatus::Active]);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$active->id}/unsuspend", [])
        ->assertUnprocessable();

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$active->id}/unsuspend", ['reason' => 'x'])
        ->assertConflict();

    $suspended = Project::factory()->create(['status' => ProjectStatus::Suspended]);
    $deployment = Deployment::factory()->for($suspended)->create(['agent_node_id' => AgentNode::factory()]);
    AgentCommand::factory()->create([
        'project_id' => $suspended->id,
        'deployment_id' => $deployment->id,
        'type' => AgentCommandType::SleepProject,
        'status' => AgentCommandStatus::Pending,
    ]);

    $this->actingAs($admin, 'web')
        ->postJson("/api/v1/admin/projects/{$suspended->id}/unsuspend", ['reason' => 'x'])
        ->assertConflict();

    expect($suspended->fresh()->status)->toBe(ProjectStatus::Suspended);
});

test('a retried unsuspend changes nothing twice, and a reused key is refused', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Suspended]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $headers = ['Idempotency-Key' => '018f6d3a-0000-7000-8000-000000000002'];

    $this->actingAs($admin, 'web')->withHeaders($headers)
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima.'])
        ->assertAccepted();
    $this->actingAs($admin, 'web')->withHeaders($headers)
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima.'])
        ->assertAccepted();

    expect(ProjectControlRequest::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(inAppNotices($owner))->toHaveCount(1);

    $this->actingAs($admin, 'web')->withHeaders($headers)
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Alasan lain.'])
        ->assertConflict();
});
