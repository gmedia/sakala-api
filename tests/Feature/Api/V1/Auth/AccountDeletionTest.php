<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationCategory;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Enums\WorkspaceRole;
use App\Http\Requests\Api\V1\Auth\DeleteAccountRequest;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Feedback;
use App\Models\GithubInstallation;
use App\Models\LegalAcceptance;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\Account\AccountDeletedNotification;
use App\Services\Notification\UserNotifier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('sakala.email_delivery_enabled', true);
});

// The console's origin is what gives a request a session, and the action ends that session.
const DELETING_FROM = 'http://app.sakala.localhost:5173';

function deleteAccount(object $test, User $user, array $body = []): object
{
    return $test->withHeader('Origin', DELETING_FROM)->actingAs($user, 'web')->deleteJson(route('api.v1.auth.account.destroy'), [
        'email' => $user->email,
        'current_password' => 'password',
        ...$body,
    ]);
}

// ─── Re-authentication ───────────────────────────────────────────────────────

test('deleting needs a session', function (): void {
    $this->deleteJson(route('api.v1.auth.account.destroy'), ['email' => 'x@example.test'])->assertUnauthorized();
});

test('a bearer token cannot delete the account', function (): void {
    $user = User::factory()->create();

    $this->withToken($user->createToken('machine')->plainTextToken)
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email, 'current_password' => 'password'])
        ->assertUnauthorized();

    expect($user->fresh())->not->toBeNull();
});

test('a wrong password or a missing one keeps the account', function (array $body): void {
    $user = User::factory()->create();

    $this->withHeader('Origin', DELETING_FROM)->actingAs($user, 'web')
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email, ...$body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh())->not->toBeNull();
})->with([
    'wrong' => [['current_password' => 'not-my-password']],
    'missing' => [[]],
]);

test('the typed email must be the email of this account', function (): void {
    $user = User::factory()->create();

    deleteAccount($this, $user, ['email' => 'someone-else@example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($user->fresh())->not->toBeNull();
});

test('an account without a password needs a recent login at its provider', function (): void {
    $user = User::factory()->create(['password' => null]);

    $this->withHeader('Origin', DELETING_FROM)->actingAs($user, 'web')
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email])
        ->assertForbidden()
        ->assertJsonPath('code', 'REAUTHENTICATION_REQUIRED');

    $this->withHeader('Origin', DELETING_FROM)
        ->withSession([DeleteAccountRequest::OAUTH_AUTHENTICATED_AT => now()->subMinutes(16)->getTimestamp()])
        ->actingAs($user, 'web')
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email])
        ->assertForbidden()
        ->assertJsonPath('code', 'REAUTHENTICATION_REQUIRED');

    expect($user->fresh())->not->toBeNull();

    $this->withHeader('Origin', DELETING_FROM)
        ->withSession([DeleteAccountRequest::OAUTH_AUTHENTICATED_AT => now()->subMinutes(5)->getTimestamp()])
        ->actingAs($user, 'web')
        ->deleteJson(route('api.v1.auth.account.destroy'), ['email' => $user->email])
        ->assertNoContent();

    expect($user->fresh())->toBeNull();
});

test('an admin cannot delete the account from here', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    deleteAccount($this, $admin)
        ->assertConflict()
        ->assertJsonPath('code', 'ADMIN_ACCOUNT_CANNOT_BE_DELETED');

    expect($admin->fresh())->not->toBeNull();
});

test('deleting is rate limited per user', function (): void {
    config()->set('sakala.rate_limits.account_security', 1);
    $user = User::factory()->create();

    deleteAccount($this, $user, ['current_password' => 'wrong'])->assertUnprocessable();
    deleteAccount($this, $user, ['current_password' => 'wrong'])->assertTooManyRequests();
});

// ─── What goes ───────────────────────────────────────────────────────────────

test('deleting removes personal data and every way back in', function (): void {
    Notification::fake();
    Storage::fake('avatars');
    Storage::disk('avatars')->put('avatars/me.png', 'bytes');

    $user = User::factory()->create(['avatar_path' => 'avatars/me.png', 'pending_email' => 'next@example.test']);
    $email = $user->email;
    $id = $user->id;
    OAuthAccount::factory()->for($user)->create();
    $installation = GithubInstallation::factory()->create();
    $installation->users()->attach($user, ['last_verified_at' => now()]);
    Feedback::factory()->create(['user_id' => $id]);
    $user->createToken('machine');
    app(UserNotifier::class)->send($user, NotificationCategory::AccountSecurity, 'x', 'y');
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'x', 'created_at' => now()]);

    deleteAccount($this, $user)->assertNoContent();

    expect(User::query()->whereKey($id)->exists())->toBeFalse()
        ->and(OAuthAccount::query()->where('user_id', $id)->count())->toBe(0)
        ->and(Feedback::query()->where('user_id', $id)->count())->toBe(0)
        ->and(LegalAcceptance::query()->where('user_id', $id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $id)->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $email)->count())->toBe(0)
        ->and(GithubInstallation::query()->whereKey($installation->id)->exists())->toBeFalse();

    Storage::disk('avatars')->assertMissing('avatars/me.png');

    $audit = AuditEvent::query()->where('action', 'account.deleted')->sole();

    expect((string) $audit->actor_id)->toBe((string) $id)
        ->and(json_encode($audit->getAttributes()))->not->toContain($email);
});

test('the session stops working on the very next request', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    deleteAccount($this, $user)->assertNoContent();

    $this->assertGuest('web');
    $this->getJson(route('api.v1.auth.user'))->assertUnauthorized();
});

test('old links die with the account', function (): void {
    Notification::fake();
    $user = User::factory()->create(['pending_email' => 'next@example.test']);

    $change = URL::temporarySignedRoute('email-change.confirm', now()->addHour(), ['user' => $user->id, 'hash' => sha1('next@example.test')]);
    $verify = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['user' => $user->id, 'hash' => sha1($user->email)]);

    deleteAccount($this, $user)->assertNoContent();

    $this->get($change)->assertRedirectContains('status=error');
    $this->get($verify)->assertRedirectContains('status=error');
});

test('the same email can register again as a new account', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $email = $user->email;
    $id = $user->id;

    deleteAccount($this, $user)->assertNoContent();

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Baru',
        'email' => $email,
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ])->assertAccepted();

    expect(User::query()->where('email', $email)->sole()->id)->not->toBe($id);
});

// ─── Projects ────────────────────────────────────────────────────────────────

test('deleting stops workloads through the normal delete path and erases secrets at once', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $node = AgentNode::factory()->create();
    $project = Project::factory()->for($user)->create(['runtime_status' => RuntimeStatus::Running]);
    $deployment = Deployment::factory()->for($project)->create([
        'status' => DeploymentStatus::Succeeded,
        'agent_node_id' => $node->id,
        'requested_by' => $user->id,
    ]);
    $project->update(['active_deployment_id' => $deployment->id]);
    $variable = EnvironmentVariable::factory()->for($project)->create();

    deleteAccount($this, $user)->assertNoContent();

    $trashed = Project::withTrashed()->findOrFail($project->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->user_id)->toBeNull()
        ->and(EnvironmentVariable::query()->whereKey($variable->id)->exists())->toBeFalse()
        ->and($deployment->fresh()->requested_by)->toBeNull();

    $stop = AgentCommand::query()->where('project_id', $project->id)->where('type', AgentCommandType::StopProject)->sole();

    expect($stop->status)->toBe(AgentCommandStatus::Pending)
        ->and($stop->agent_node_id)->toBe($node->id);
});

test('a project without an owner still leaves through the purge', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    deleteAccount($this, $user)->assertNoContent();

    $this->travel(31)->days();
    Artisan::call('projects:purge-deleted');

    expect(Project::withTrashed()->whereKey($project->id)->exists())->toBeFalse();
});

test('a late agent report on such a project finds nobody to notify and does not fail', function (): void {
    expect(fn () => app(UserNotifier::class)->send(null, NotificationCategory::DeploymentFailure, 'x', 'y'))->not->toThrow(Throwable::class);
});

test('repeating after a partial run finishes the job', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    Project::factory()->for($user)->create();
    Project::factory()->for($user)->create()->delete();

    deleteAccount($this, $user)->assertNoContent();

    expect(Project::withTrashed()->whereNull('user_id')->count())->toBe(2);
});

test('the database refuses to remove an owner who still has a live project', function (): void {
    $user = User::factory()->create();
    Project::factory()->for($user)->create();

    expect(fn () => $user->delete())->toThrow(QueryException::class);
});

// ─── Other people ────────────────────────────────────────────────────────────

test('nothing belonging to someone else changes', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherProject = Project::factory()->for($other)->create();
    $otherVariable = EnvironmentVariable::factory()->for($otherProject)->create();
    $otherFeedback = Feedback::factory()->create(['user_id' => $other->id]);
    $otherOauth = OAuthAccount::factory()->for($other)->create();
    $otherToken = $other->createToken('machine')->accessToken;
    app(UserNotifier::class)->send($other, NotificationCategory::AccountSecurity, 'x', 'y');
    DB::table('sessions')->insert(['id' => 'other-user', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    // Connected by both, so it stays.
    $shared = GithubInstallation::factory()->create();
    $shared->users()->attach([$user->id, $other->id], ['last_verified_at' => now()]);
    Project::factory()->for($user)->create();

    deleteAccount($this, $user)->assertNoContent();

    expect($other->fresh())->not->toBeNull()
        ->and($otherProject->fresh()?->trashed())->toBeFalse()
        ->and($otherProject->fresh()?->user_id)->toBe($other->id)
        ->and(EnvironmentVariable::query()->whereKey($otherVariable->id)->exists())->toBeTrue()
        ->and(Feedback::query()->whereKey($otherFeedback->id)->exists())->toBeTrue()
        ->and(OAuthAccount::query()->whereKey($otherOauth->id)->exists())->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('id', $otherToken->id)->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('notifiable_id', $other->id)->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($other->legalAcceptances()->count())->toBe(3)
        ->and(GithubInstallation::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and($shared->users()->whereKey($other->id)->exists())->toBeTrue();
});

// ─── Confirmation email ──────────────────────────────────────────────────────

test('a confirmation goes to the old address when email can be delivered', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $email = $user->email;

    deleteAccount($this, $user)->assertNoContent();

    Notification::assertSentOnDemand(
        AccountDeletedNotification::class,
        static fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $email,
    );
});

test('without email delivery the account still goes and nothing is sent', function (): void {
    Notification::fake();
    config()->set('sakala.email_delivery_enabled', false);
    $user = User::factory()->create();

    deleteAccount($this, $user)->assertNoContent();

    Notification::assertNothingSent();
    expect($user->fresh())->toBeNull();
});

test('the last owner of a team that still has projects cannot delete the account, and nothing is touched', function (): void {
    $user = User::factory()->create();
    $team = teamWorkspace($user, [[User::factory()->create(), WorkspaceRole::Member]], ['name' => 'Tim Facility']);
    Project::factory()->inWorkspace($team)->create();
    $personalProject = Project::factory()->for($user)->create();

    deleteAccount($this, $user)
        ->assertConflict()
        ->assertJsonPath('code', 'LAST_WORKSPACE_OWNER')
        ->assertJsonPath('workspaces.0.id', $team->id)
        ->assertJsonPath('workspaces.0.name', 'Tim Facility');

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(Project::query()->whereKey($personalProject->id)->exists())->toBeTrue();
});

test('deleting an account removes the personal workspace and memberships, deletes empty teams it owned alone, and leaves other teams', function (): void {
    $user = User::factory()->create();
    $coOwner = User::factory()->create();
    $personal = $user->personalWorkspace()->firstOrFail();
    $personalProject = Project::factory()->for($user)->create();
    $emptyTeam = teamWorkspace($user, [[User::factory()->create(), WorkspaceRole::Viewer]]);
    $sharedTeam = teamWorkspace($coOwner, [[$user, WorkspaceRole::Owner]]);
    $teamProject = Project::factory()->inWorkspace($sharedTeam, $user)->create();
    $memberTeam = teamWorkspace(User::factory()->create(), [[$user, WorkspaceRole::Member]]);

    deleteAccount($this, $user)->assertNoContent();

    expect(Workspace::query()->whereKey($personal->id)->exists())->toBeFalse()
        ->and(Workspace::query()->whereKey($emptyTeam->id)->exists())->toBeFalse()
        ->and(Workspace::query()->whereKey($sharedTeam->id)->exists())->toBeTrue()
        ->and(Workspace::query()->whereKey($memberTeam->id)->exists())->toBeTrue()
        ->and(WorkspaceMember::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Project::query()->whereKey($personalProject->id)->exists())->toBeFalse()
        ->and(Project::withTrashed()->whereKey($personalProject->id)->first()?->workspace_id)->toBeNull()
        // The team keeps the project; only its creator is gone.
        ->and(Project::query()->whereKey($teamProject->id)->first()?->user_id)->toBeNull()
        ->and(Project::query()->whereKey($teamProject->id)->first()?->workspace_id)->toBe($sharedTeam->id)
        ->and(AuditEvent::query()->where('action', 'workspace.deleted')->where('subject_id', $emptyTeam->id)->exists())->toBeTrue();
});

test('an installation a team project still uses survives the deletion of the account that connected it', function (): void {
    $user = User::factory()->create();
    $team = teamWorkspace(User::factory()->create(), [[$user, WorkspaceRole::Member]]);
    $installation = GithubInstallation::factory()->create(['account_type' => 'Organization']);
    $installation->users()->attach($user, ['last_verified_at' => now()]);
    Project::factory()->inWorkspace($team, $user)->create(['github_installation_id' => $installation->id, 'github_repository_id' => 9]);

    deleteAccount($this, $user)->assertNoContent();

    expect(GithubInstallation::query()->whereKey($installation->id)->exists())->toBeTrue();
});
