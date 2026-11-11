<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\Workspace\WorkspaceInvitationNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['sakala.email_delivery_enabled' => true]);
    Notification::fake();
});

/**
 * Invites $email into a new team owned by a fresh user and returns the team, the invitation, and the plain token
 * from the email.
 *
 * @return array{0: Workspace, 1: WorkspaceInvitation, 2: string, 3: User}
 */
function invitedWithToken(string $email, string $role = 'member', ?Workspace $team = null, ?User $owner = null): array
{
    $owner ??= User::factory()->create(['name' => 'Dina']);
    $team ??= teamWorkspace($owner, attributes: ['name' => 'GMEDIA Magang']);

    test()->actingAs($owner, 'web')
        ->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => $email, 'role' => $role])
        ->assertCreated();

    $token = null;
    Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function (WorkspaceInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if (($notifiable->routes['mail'] ?? null) !== strtolower($email)) {
            return false;
        }

        $token = $notification->token;

        return true;
    });

    return [$team, WorkspaceInvitation::query()->pending()->where('email', strtolower($email))->firstOrFail(), (string) $token, $owner];
}

test('the holder of the link reads the invitation without seeing the invited address', function (): void {
    [$team, $invitation, $token, $owner] = invitedWithToken('kampus@example.test');
    $holder = User::factory()->create(['email' => 'pribadi@example.test']);

    $response = $this->actingAs($holder, 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $invitation->id,
            'role' => 'member',
            'expires_at' => $invitation->expires_at->toAtomString(),
            'created_at' => $invitation->created_at?->toAtomString(),
            'workspace' => ['id' => $team->id, 'name' => 'GMEDIA Magang', 'slug' => $team->slug],
            'invited_by' => ['id' => $owner->id, 'name' => 'Dina', 'username' => $owner->username],
            'restricted_to_invited_email' => false,
            'can_accept' => true,
            'accept_error_code' => null,
        ]]);

    expect($response->getContent())->not->toContain('kampus@example.test');
});

test('the invited verified address reads it without the token', function (): void {
    [, $invitation] = invitedWithToken('rani@example.test', 'viewer');
    $invitee = User::factory()->create(['email' => 'rani@example.test']);

    $this->actingAs($invitee, 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}")
        ->assertOk()
        ->assertJsonPath('data.role', 'viewer')
        ->assertJsonPath('data.can_accept', true);
});

test('an owner invitation read from another account says it is restricted, without the address', function (): void {
    [, $invitation, $token] = invitedWithToken('Co-Owner@example.test', 'owner');

    $response = $this->actingAs(User::factory()->create(), 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertOk()
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.restricted_to_invited_email', true)
        ->assertJsonPath('data.can_accept', false)
        ->assertJsonPath('data.accept_error_code', 'INVITATION_EMAIL_MISMATCH');

    expect(strtolower((string) $response->getContent()))->not->toContain('co-owner@example.test');

    $invitee = User::factory()->create(['email' => 'co-owner@example.test']);
    $this->actingAs($invitee, 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertOk()
        ->assertJsonPath('data.restricted_to_invited_email', true)
        ->assertJsonPath('data.can_accept', true)
        ->assertJsonPath('data.accept_error_code', null);
});

test('the read says when this account cannot accept: already a member, or at the owned-team cap', function (): void {
    config(['sakala.workspaces.max_owned_teams_per_user' => 1]);
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $team = teamWorkspace($owner, [[$member, WorkspaceRole::Viewer]]);
    [, $invitation, $token] = invitedWithToken('elsewhere@example.test', team: $team, owner: $owner);

    $this->actingAs($member, 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertOk()
        ->assertJsonPath('data.can_accept', false)
        ->assertJsonPath('data.accept_error_code', 'ALREADY_WORKSPACE_MEMBER');

    $busy = User::factory()->create(['email' => 'busy@example.test']);
    teamWorkspace($busy);
    [, $ownerInvitation] = invitedWithToken('busy@example.test', 'owner', $team, $owner);

    $this->actingAs($busy, 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$ownerInvitation->id}")
        ->assertOk()
        ->assertJsonPath('data.can_accept', false)
        ->assertJsonPath('data.accept_error_code', 'TEAM_LIMIT_EXCEEDED');

    // The read agrees with what accepting answers.
    $this->actingAs($busy, 'web')->postJson("/api/v1/app/workspace-invitations/{$ownerInvitation->id}/accept")
        ->assertUnprocessable()->assertJsonPath('code', 'TEAM_LIMIT_EXCEEDED');
});

test('reading needs a session and a verified email', function (): void {
    [, $invitation, $token] = invitedWithToken('x@example.test');

    $this->app['auth']->forgetGuards();
    $this->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))->assertUnauthorized();

    $this->actingAs(User::factory()->unverified()->create(), 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertForbidden()
        ->assertJsonPath('code', 'EMAIL_VERIFICATION_REQUIRED');

    // Without the proof, an unverified account learns nothing either.
    $this->actingAs(User::factory()->unverified()->create(), 'web')
        ->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=wrong")
        ->assertNotFound();
});

/**
 * Closes the invitation the way $state says, through the API where there is one.
 *
 * @param  array{0: Workspace, 1: WorkspaceInvitation, 2: string, 3: User}  $invited
 */
function closeInvitation(string $state, array $invited): void
{
    [$team, $invitation, $token, $owner] = $invited;

    match ($state) {
        'accepted' => test()->actingAs(User::factory()->create(), 'web')
            ->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])->assertOk(),
        'declined' => test()->actingAs(User::factory()->create(), 'web')
            ->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/decline", ['token' => $token])->assertNoContent(),
        'revoked' => test()->actingAs($owner, 'web')
            ->deleteJson("/api/v1/app/workspaces/{$team->id}/invitations/{$invitation->id}")->assertNoContent(),
        'replaced' => test()->actingAs($owner, 'web')
            ->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => $invitation->email, 'role' => 'viewer'])->assertCreated(),
        'expired' => test()->travel(8)->days(),
        default => null,
    };
}

dataset('invitation states', [
    'pending' => ['pending'],
    'accepted' => ['accepted'],
    'declined' => ['declined'],
    'revoked' => ['revoked'],
    'replaced by a newer invitation' => ['replaced'],
    'expired' => ['expired'],
]);

test('a wrong or missing token is the same 404 whatever the state', function (string $state): void {
    // As in production, where the body of a 404 is only its message.
    config(['sakala.rate_limits.account_security' => 100, 'app.debug' => false]);
    $invited = invitedWithToken('anyone@example.test');
    closeInvitation($state, $invited);
    $id = $invited[1]->id;
    $stranger = User::factory()->create();
    $missingId = Str::uuid7()->toString();
    $missing = $this->actingAs($stranger, 'web')->getJson("/api/v1/app/workspace-invitations/{$missingId}?token=x")->assertNotFound();
    $expected = str_replace($missingId, $id, (string) $missing->getContent());

    foreach (['', '?token=', '?token=wrong', '?token='.str_repeat('a', 64)] as $query) {
        $read = $this->actingAs($stranger, 'web')->getJson("/api/v1/app/workspace-invitations/{$id}{$query}")->assertNotFound();
        expect($read->getContent())->toBe($expected);

        $this->actingAs($stranger, 'web')->postJson("/api/v1/app/workspace-invitations/{$id}/accept", ['token' => ltrim(strstr($query, '=') ?: '', '=')])->assertNotFound();
        $this->actingAs($stranger, 'web')->postJson("/api/v1/app/workspace-invitations/{$id}/decline", ['token' => ltrim(strstr($query, '=') ?: '', '=')])->assertNotFound();
    }
})->with('invitation states');

test('the right token learns why the invitation no longer works', function (string $state, string $code): void {
    $invited = invitedWithToken('anyone@example.test');
    closeInvitation($state, $invited);
    [, $invitation, $token] = $invited;
    $holder = User::factory()->create();

    $this->actingAs($holder, 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertConflict()->assertJsonPath('code', $code)->assertJsonStructure(['message', 'code']);
    $this->actingAs($holder, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])
        ->assertConflict()->assertJsonPath('code', $code);
    $this->actingAs($holder, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/decline", ['token' => $token])
        ->assertConflict()->assertJsonPath('code', $code);
})->with([
    'accepted' => ['accepted', 'INVITATION_ALREADY_ACCEPTED'],
    'declined' => ['declined', 'INVITATION_DECLINED'],
    'revoked' => ['revoked', 'INVITATION_REVOKED'],
    'replaced by a newer invitation' => ['replaced', 'INVITATION_REVOKED'],
    'expired' => ['expired', 'INVITATION_EXPIRED'],
]);

test('an invitation revoked because its inviter lost the owner role says so to the holder', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $team = teamWorkspace($first, [[$second, WorkspaceRole::Owner]]);
    [, $invitation, $token] = invitedWithToken('gone@example.test', team: $team, owner: $second);

    $membership = $team->members()->where('user_id', $second->id)->firstOrFail();
    $this->actingAs($first, 'web')->deleteJson("/api/v1/app/workspaces/{$team->id}/members/{$membership->id}")->assertNoContent();

    $this->actingAs(User::factory()->create(), 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))
        ->assertConflict()->assertJsonPath('code', 'INVITATION_REVOKED');
});

test('the invited address sees the closed state from its own account too, while others get 404', function (): void {
    [, $invitation] = invitedWithToken('rani@example.test');
    $invitee = User::factory()->create(['email' => 'rani@example.test']);
    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/decline")->assertNoContent();

    $this->actingAs($invitee, 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}")
        ->assertConflict()->assertJsonPath('code', 'INVITATION_DECLINED');
    $this->actingAs(User::factory()->create(), 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}")
        ->assertNotFound();
});

test('a closed invitation does not block inviting the same address again', function (): void {
    [$team, $invitation, , $owner] = invitedWithToken('again@example.test');
    $invitee = User::factory()->create(['email' => 'again@example.test']);
    $this->actingAs($invitee, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/decline")->assertNoContent();

    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'again@example.test', 'role' => 'viewer'])
        ->assertCreated();
    $this->actingAs($owner, 'web')->postJson("/api/v1/app/workspaces/{$team->id}/invitations", ['email' => 'again@example.test', 'role' => 'member'])
        ->assertCreated();

    expect(WorkspaceInvitation::query()->where('email', 'again@example.test')->count())->toBe(3)
        ->and(WorkspaceInvitation::query()->pending()->where('email', 'again@example.test')->count())->toBe(1);
    $this->actingAs($invitee, 'web')->getJson('/api/v1/app/workspace-invitations')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'member');
});

test('the database keeps one pending invitation per address per workspace', function (): void {
    [$team, $invitation] = invitedWithToken('unique@example.test');

    // In a savepoint, so PostgreSQL can go on after the violation.
    expect(fn () => DB::transaction(fn () => WorkspaceInvitation::query()->create([
        'workspace_id' => $team->id,
        'email' => 'unique@example.test',
        'role' => WorkspaceRole::Member,
        'token_hash' => WorkspaceInvitation::hashToken('another'),
        'expires_at' => now()->addDay(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect($invitation->refresh()->isPending())->toBeTrue();
});

test('reading shares the rate limit of accepting and declining', function (): void {
    config(['sakala.rate_limits.account_security' => 2]);
    [, $invitation, $token] = invitedWithToken('slow@example.test');
    $holder = User::factory()->create();

    $this->actingAs($holder, 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=".urlencode($token))->assertOk();
    $this->actingAs($holder, 'web')->getJson("/api/v1/app/workspace-invitations/{$invitation->id}?token=wrong")->assertNotFound();
    $this->actingAs($holder, 'web')->postJson("/api/v1/app/workspace-invitations/{$invitation->id}/accept", ['token' => $token])->assertTooManyRequests();
});
