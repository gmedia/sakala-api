<?php

declare(strict_types=1);

use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Workspace\RemoveWorkspaceMemberAction;
use App\Enums\WorkspaceRole;
use App\Exceptions\Workspace\LastWorkspaceOwnerException;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');

    // Keep the account deletion off the network: avatar storage and mail.
    Storage::fake('avatars');
    Notification::fake();
    config(['sakala.email_delivery_enabled' => false]);
});

test('an owner deleting the account waits for a co-owner leaving the same team, so the team keeps an owner', function (): void {
    $deleting = User::factory()->create();
    $leaving = User::factory()->create();
    $team = teamWorkspace($deleting, [[$leaving, WorkspaceRole::Owner]]);
    Project::factory()->inWorkspace($team, $leaving)->create();
    $leavingMembership = WorkspaceMember::query()->where('workspace_id', $team->id)->where('user_id', $leaving->id)->firstOrFail();

    // Transaction A is the co-owner leaving: it holds the team row and has
    // removed its own membership, not yet committed. The account deletion
    // must wait on that row before it decides who else owns the team.
    whileTransactionHoldsLocks(
        holdLocks: fn () => app(RemoveWorkspaceMemberAction::class)->handle($leaving, $team, $leavingMembership),
        // Read the user again on the contending session: a model made by the
        // factory keeps the first connection and would write through it.
        contend: fn () => app(DeleteAccountAction::class)->handle(User::query()->findOrFail($deleting->id)),
    );

    // With the leave committed, the deleting account is the last owner of a
    // team with a project, so it is refused and the team keeps its owner.
    expect(fn () => app(DeleteAccountAction::class)->handle(User::query()->findOrFail($deleting->id)))
        ->toThrow(LastWorkspaceOwnerException::class);

    expect(User::query()->whereKey($deleting->id)->exists())->toBeTrue()
        ->and(WorkspaceMember::query()->where('workspace_id', $team->id)->where('role', WorkspaceRole::Owner)->count())->toBe(1);
});
