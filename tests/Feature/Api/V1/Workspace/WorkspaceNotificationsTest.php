<?php

declare(strict_types=1);

use App\Enums\NotificationCategory;
use App\Enums\WorkspaceRole;
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
});

test('the in-app invitation notice carries the invitation ID, and the notification list shows it', function (): void {
    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'rani@example.test']);
    $team = teamWorkspace($owner);

    $id = $this->actingAs($owner, 'web')
        ->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'rani@example.test', 'role' => 'viewer'])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($invitee, 'web')->getJson(route('api.v1.app.notifications.index'))
        ->assertOk()
        ->assertJsonPath('data.0.category', 'workspace')
        ->assertJsonPath('data.0.invitation_id', $id)
        ->assertJsonPath('data.0.workspace_id', null)
        ->assertJsonPath('data.0.project_id', null);
});

test('with the workspace category off, the invitation email is still sent but the in-app notice is not', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $invitee = User::factory()->create([
        'email' => 'quiet@example.test',
        'notification_preferences' => [NotificationCategory::Workspace->value => false],
    ]);
    $team = teamWorkspace($owner);

    $this->actingAs($owner, 'web')
        ->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'quiet@example.test', 'role' => 'member'])
        ->assertCreated();

    Notification::assertSentOnDemand(
        WorkspaceInvitationNotification::class,
        fn (WorkspaceInvitationNotification $mail, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'quiet@example.test',
    );
    Notification::assertNotSentTo($invitee, UserAlertNotification::class);
});

test('the inviter hears when the invitation is accepted, unless the category is off', function (): void {
    $owner = User::factory()->create();
    $team = teamWorkspace($owner, attributes: ['name' => 'Kelas A']);
    $invitee = User::factory()->create(['email' => 'join@example.test', 'name' => 'Rani']);
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'join@example.test', 'role' => 'member']);
    $invitation = WorkspaceInvitation::query()->firstOrFail();

    Notification::fake();
    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept")->assertOk();

    Notification::assertSentTo($owner, UserAlertNotification::class, fn (UserAlertNotification $notice): bool => $notice->category === NotificationCategory::Workspace
        && $notice->invitationId === $invitation->id
        && $notice->workspaceId === $team->id
        && str_contains($notice->message, 'Rani')
        && str_contains($notice->message, 'Kelas A')
        && str_contains($notice->message, 'anggota'));

    $quietOwner = User::factory()->create(['notification_preferences' => ['workspace' => false]]);
    $quietTeam = teamWorkspace($quietOwner);
    $this->actingAs($quietOwner, 'web')->postJson("/api/v1/app/workspaces/{$quietTeam->id}/invitations", ['email' => 'join@example.test', 'role' => 'viewer']);
    $second = WorkspaceInvitation::query()->pending()->firstOrFail();

    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$second->id}/accept")->assertOk();

    Notification::assertNotSentTo($quietOwner, UserAlertNotification::class);
});

test('a member hears about a role change made by an owner, not about their own', function (): void {
    Notification::fake();
    $owner = User::factory()->create(['name' => 'Dina']);
    $member = User::factory()->create();
    $coOwner = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Member], [$coOwner, WorkspaceRole::Owner]], ['name' => 'Kelas A']);
    $membership = $team->members()->where('user_id', $member->id)->firstOrFail();

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$membership->id}", ['role' => 'viewer'])->assertOk();

    Notification::assertSentTo($member, UserAlertNotification::class, fn (UserAlertNotification $notice): bool => $notice->category === NotificationCategory::Workspace
        && $notice->workspaceId === $team->id
        && $notice->invitationId === null
        && str_contains($notice->message, 'dari anggota menjadi pengamat'));

    $own = $team->members()->where('user_id', $coOwner->id)->firstOrFail();
    $this->actingAs($coOwner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$own->id}", ['role' => 'member'])->assertOk();

    Notification::assertNotSentTo($coOwner, UserAlertNotification::class);
});

test('a removed member hears about it without a link to the team; leaving sends nothing', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $removed = User::factory()->create();
    $leaver = User::factory()->create();
    $team = teamWorkspace($owner, [[$removed, WorkspaceRole::Member], [$leaver, WorkspaceRole::Viewer]]);
    $membership = $team->members()->where('user_id', $removed->id)->firstOrFail();

    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/members/{$membership->id}")->assertNoContent();
    $this->actingAs($leaver, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/leave")->assertNoContent();

    Notification::assertSentTo($removed, UserAlertNotification::class, fn (UserAlertNotification $notice): bool => $notice->category === NotificationCategory::Workspace
        && $notice->workspaceId === null);
    Notification::assertNotSentTo($leaver, UserAlertNotification::class);
    Notification::assertNotSentTo($owner, UserAlertNotification::class);
});

test('membership notices respect the workspace category', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $quiet = User::factory()->create(['notification_preferences' => ['workspace' => false]]);
    $team = teamWorkspace($owner, [[$quiet, WorkspaceRole::Member]]);
    $membership = $team->members()->where('user_id', $quiet->id)->firstOrFail();

    $this->actingAs($owner, 'web')->patchJson("/api/v1/app/workspaces/{$team->id}/members/{$membership->id}", ['role' => 'viewer'])->assertOk();
    $this->actingAs($owner, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/members/{$membership->id}")->assertNoContent();

    Notification::assertNotSentTo($quiet, UserAlertNotification::class);
});
