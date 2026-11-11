<?php

declare(strict_types=1);

use App\Enums\NotificationCategory;
use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\UserAlertNotification;
use App\Notifications\Workspace\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sakala.email_delivery_enabled' => true]);
    Notification::fake();
});

/** The plain token from the invitation email sent to $email. */
function invitationTokenSentTo(string $email): string
{
    $token = null;

    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function (WorkspaceInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }

        $token = $notification->token;

        return true;
    });

    return (string) $token;
}

test('an owner invites an email address and only a hash of the token is stored', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['name' => 'Tim Facility']);

    $response = $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", [
        'email' => 'Magang@Example.TEST',
        'role' => 'member',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.email', 'magang@example.test')
        ->assertJsonPath('data.role', 'member')
        ->assertJsonPath('data.workspace.id', $team->id)
        ->assertJsonPath('data.invited_by.id', $owner->id)
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    $token = invitationTokenSentTo('magang@example.test');
    $invitation = WorkspaceInvitation::query()->findOrFail($response->json('data.id'));

    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token)
        ->and($invitation->expires_at->greaterThan(now()->addDays(6)))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'workspace.invitation_created')->firstOrFail()->metadata)
        ->toBe(['invitation_id' => $invitation->id, 'role' => 'member']);

    $mail = (new WorkspaceInvitationNotification($invitation->id, $token, 'Tim Facility', $owner->name, 'member', 168))
        ->toMail(new AnonymousNotifiable);
    expect($mail->actionUrl)->toContain("/workspace-invitations/{$invitation->id}?token=");
});

test('an existing account with the invited verified email also gets an in-app notice without the token', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'rani@example.test']);
    $team = teamWorkspace($owner);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'rani@example.test', 'role' => 'viewer'])
        ->assertCreated();

    $token = invitationTokenSentTo('rani@example.test');

    Notification::assertSentTo($invitee, UserAlertNotification::class, function (UserAlertNotification $notification) use ($token): bool {
        return $notification->category === NotificationCategory::Workspace
            && ! str_contains($notification->message, $token);
    });
});

test('only owners of a team invite, and never into a personal workspace', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]]);

    $this->actingAs($member, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'a@example.test', 'role' => 'member'])
        ->assertForbidden();
    $this->actingAs(User::factory()->create(), 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'a@example.test', 'role' => 'member'])
        ->assertForbidden();

    $personal = $owner->personalWorkspace()->firstOrFail();
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$personal->id}/invitations", ['email' => 'a@example.test', 'role' => 'member'])
        ->assertConflict()->assertJsonPath('code', 'WORKSPACE_IS_PERSONAL');

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'not-an-email', 'role' => 'admin'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email', 'role']);

    Notification::assertNothingSent();
});

test('inviting is refused while email delivery is off', function (): void {
    config(['sakala.email_delivery_enabled' => false]);
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'a@example.test', 'role' => 'member'])
        ->assertServiceUnavailable()
        ->assertJsonPath('code', 'EMAIL_DELIVERY_UNAVAILABLE');

    expect(WorkspaceInvitation::query()->count())->toBe(0);
});

test('inviting a current member is refused; inviting again replaces the pending invitation', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.test']);
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member]]);

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'MEMBER@example.test', 'role' => 'viewer'])
        ->assertConflict()->assertJsonPath('code', 'ALREADY_WORKSPACE_MEMBER');

    $first = $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'new@example.test', 'role' => 'viewer'])->json('data.id');
    $second = $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'new@example.test', 'role' => 'member'])->json('data.id');

    expect(WorkspaceInvitation::query()->pending()->pluck('id')->all())->toBe([$second])
        ->and(WorkspaceInvitation::query()->findOrFail($first)->status)->toBe(WorkspaceInvitationStatus::Revoked)
        ->and(AuditEvent::query()->where('action', 'workspace.invitation_revoked')->firstOrFail()->metadata)
        ->toMatchArray(['invitation_id' => $first, 'reason' => 'replaced'])
        ->and($first)->not->toBe($second);

    $this->actingAs($owner, 'web')->getJson("/api/v1/app/workspaces/{$team->id}/invitations")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'member');
});

test('the token from the email lets any verified account accept, once', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'kampus@example.test', 'role' => 'member']);
    $token = invitationTokenSentTo('kampus@example.test');
    $invitation = WorkspaceInvitation::query()->firstOrFail();
    $student = User::factory()->create(['email' => 'pribadi@example.test']);

    $this->actingAs($student, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => 'wrong'])
        ->assertNotFound();

    $this->actingAs($student, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $team->id)
        ->assertJsonPath('data.role', 'member');

    expect($team->roleOf($student))->toBe(WorkspaceRole::Member)
        ->and($invitation->refresh()->status)->toBe(WorkspaceInvitationStatus::Accepted)
        ->and($invitation->closed_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', 'workspace.member_joined')->firstOrFail()->metadata)
        ->toMatchArray(['invitation_id' => $invitation->id, 'member_user_id' => $student->id, 'role' => 'member', 'invited_by' => $owner->id]);

    $this->actingAs(User::factory()->create(), 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => 'wrong'])
        ->assertNotFound();
    $this->actingAs(User::factory()->create(), 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertConflict()
        ->assertJsonPath('code', 'INVITATION_ALREADY_ACCEPTED');
});

test('the invited verified address accepts from the in-app list without the token', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['name' => 'Kelas A']);
    $invitee = User::factory()->create(['email' => 'rani@example.test']);
    $other = User::factory()->create();
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'rani@example.test', 'role' => 'viewer']);
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs($invitee, 'web')->getJson('/api/v1/app/workspace-invitations')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.workspace.name', 'Kelas A')
        ->assertJsonPath('data.0.role', 'viewer');
    $this->actingAs($other, 'web')->getJson('/api/v1/app/workspace-invitations')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($other, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept")->assertNotFound();

    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept")
        ->assertOk()->assertJsonPath('data.role', 'viewer');
});

test('an unverified account cannot accept, even with the token', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'x@example.test', 'role' => 'member']);
    $token = invitationTokenSentTo('x@example.test');
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs(User::factory()->unverified()->create(), 'web')
        ->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertForbidden()
        ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');
});

test('an expired invitation is refused, and pruned daily', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $invitee = User::factory()->create(['email' => 'late@example.test']);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'late@example.test', 'role' => 'member']);
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->travel(8)->days();

    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept")
        ->assertConflict()->assertJsonPath('code', 'INVITATION_EXPIRED');
    $this->actingAs($invitee, 'web')->getJson('/api/v1/app/workspace-invitations')->assertJsonCount(0, 'data');

    // Kept for the retention window after expiry, so the link can still say it expired.
    $this->artisan('workspaces:prune-invitations')->assertSuccessful();
    expect(WorkspaceInvitation::query()->exists())->toBeTrue();

    $this->travel(7)->days();
    $this->artisan('workspaces:prune-invitations')->assertSuccessful();

    expect(WorkspaceInvitation::query()->exists())->toBeFalse();
});

test('accepting while already a member is refused', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Viewer]]);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'elsewhere@example.test', 'role' => 'member']);
    $token = invitationTokenSentTo('elsewhere@example.test');
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs($member, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertConflict()->assertJsonPath('code', 'ALREADY_WORKSPACE_MEMBER');

    expect($team->roleOf($member))->toBe(WorkspaceRole::Viewer)
        ->and(WorkspaceInvitation::query()->exists())->toBeTrue();
});

test('the invitee declines and an owner revokes', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $invitee = User::factory()->create(['email' => 'no@example.test']);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'no@example.test', 'role' => 'member']);
    $declined = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$declined->id}/decline")->assertNoContent();
    expect($team->roleOf($invitee))->toBeNull()
        ->and($declined->refresh()->status)->toBe(WorkspaceInvitationStatus::Declined)
        ->and(AuditEvent::query()->where('action', 'workspace.invitation_declined')->exists())->toBeTrue();
    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$declined->id}/decline")
        ->assertConflict()->assertJsonPath('code', 'INVITATION_DECLINED');

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'later@example.test', 'role' => 'member']);
    $token = invitationTokenSentTo('later@example.test');
    $revoked = WorkspaceInvitation::query()->pending()->firstOrFail();
    $otherTeam = teamWorkspace($owner);

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$otherTeam->id}/invitations/{$revoked->id}")->assertNotFound();
    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/invitations/{$revoked->id}")->assertNoContent();

    $this->actingAs(User::factory()->create(), 'web')->postJson("/api/v1/app/workspace-invitations/{$revoked->id}/accept", ['token' => $token])
        ->assertConflict()
        ->assertJsonPath('code', 'INVITATION_REVOKED');
    $this->actingAs(User::factory()->create(), 'web')->postJson("/api/v1/app/workspace-invitations/{$revoked->id}/accept", ['token' => 'wrong'])
        ->assertNotFound();
    // Already revoked: nothing left to revoke, and the owner's list no longer has it.
    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/invitations/{$revoked->id}")->assertNotFound();
    $this->actingAs($owner, 'web')->getJson("/api/v1/app/workspaces/{$team->id}/invitations")->assertOk()->assertJsonCount(0, 'data');
    expect(AuditEvent::query()->where('action', 'workspace.invitation_revoked')->count())->toBe(1);
});

test('an owner invitation is accepted only by the invited verified address, even with the token', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'Co-Owner@example.test', 'role' => 'owner']);
    $token = invitationTokenSentTo('co-owner@example.test');
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs(User::factory()->create(), 'web')
        ->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertForbidden()
        ->assertJsonPath('code', 'INVITATION_EMAIL_MISMATCH');

    expect(WorkspaceInvitation::query()->exists())->toBeTrue();

    $invitee = User::factory()->create(['email' => 'co-owner@example.test']);
    $this->actingAs($invitee, 'web')
        ->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.role', 'owner');
});

test('accepting an owner invitation respects the owned-team cap', function (): void {
    config(['sakala.workspaces.max_owned_teams_per_user' => 1]);
    $owner = User::factory()->create();
    $team = teamWorkspace($owner);
    $invitee = User::factory()->create(['email' => 'busy@example.test']);
    teamWorkspace($invitee);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'busy@example.test', 'role' => 'owner']);
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TEAM_LIMIT_EXCEEDED');

    expect($team->roleOf($invitee))->toBeNull()
        ->and(WorkspaceInvitation::query()->exists())->toBeTrue();
});
