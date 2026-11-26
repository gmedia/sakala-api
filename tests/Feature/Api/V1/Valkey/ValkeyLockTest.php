<?php

declare(strict_types=1);

use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Workspace\CreatePersonalWorkspaceAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\ValkeyLockSource;
use App\Enums\ValkeyStatus;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableValkey();
});

test('admins lock a store for abuse with a reason, and unlock it again', function (): void {
    $node = valkeyNode();
    $valkey = valkeyIn(valkeyTeam()['workspace'], $node);

    $this->artisan('valkeys:lock', ['valkey' => $valkey->id])->assertExitCode(2);
    $this->artisan('valkeys:lock', ['valkey' => $valkey->id, '--reason' => 'Spam antrean'])->assertSuccessful();

    $lock = valkeyCommand($valkey, AgentCommandType::LockValkey);

    expect($lock->payload)->toBe(['valkey_id' => $valkey->id])
        ->and($lock->request_context)->toBe(['lock_source' => 'admin'])
        ->and(AuditEvent::query()->where('action', 'valkey.lock_requested')->sole()->metadata)->toMatchArray(['reason' => 'Spam antrean', 'lock_source' => 'admin']);

    // Only one lock or unlock at a time.
    $this->artisan('valkeys:lock', ['valkey' => $valkey->id, '--reason' => 'lagi'])->assertFailed();

    $lock->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    completeValkeyCommand($node, $lock, valkeyResultFor($valkey, AgentCommandType::LockValkey));

    $this->artisan('valkeys:unlock', ['valkey' => $valkey->id])->assertSuccessful();

    expect(valkeyCommand($valkey, AgentCommandType::UnlockValkey)->status)->toBe(AgentCommandStatus::Pending);
    $this->artisan('valkeys:lock', ['valkey' => '00000000-0000-0000-0000-000000000000', '--reason' => 'x'])->assertFailed();
});

test('suspending the only project of a store locks it; unsuspending unlocks it', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node);
    attachValkey($valkey, $project);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin, 'web')->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Phishing.'])->assertSuccessful();

    $lock = valkeyCommand($valkey, AgentCommandType::LockValkey);

    expect($lock->request_context)->toBe(['lock_source' => 'suspension']);

    $lock->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);
    completeValkeyCommand($node, $lock, valkeyResultFor($valkey, AgentCommandType::LockValkey));

    expect($valkey->fresh())->status->toBe(ValkeyStatus::Locked)->lock_source->toBe(ValkeyLockSource::Suspension);

    $this->actingAs($admin, 'web')->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima.'])->assertSuccessful();

    expect(valkeyCommand($valkey, AgentCommandType::UnlockValkey)->status)->toBe(AgentCommandStatus::Pending);
});

test('a store shared with a project that is not suspended stays unlocked', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $other = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create();
    $valkey = valkeyIn($team['workspace'], $node);
    attachValkey($valkey, $project);
    attachValkey($valkey, $other);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin, 'web')->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => 'Phishing.'])->assertSuccessful();

    expect(AgentCommand::query()->where('type', AgentCommandType::LockValkey)->count())->toBe(0);
});

test('unsuspending leaves an admin lock alone', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node, ['status' => ValkeyStatus::Locked, 'lock_source' => ValkeyLockSource::Admin]);
    attachValkey($valkey, $project);
    $project->update(['status' => ProjectStatus::Suspended]);
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin, 'web')->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => 'Banding diterima.'])->assertSuccessful();

    expect(AgentCommand::query()->where('type', AgentCommandType::UnlockValkey)->count())->toBe(0);
});

test('a suspension lock that lands after the project was unsuspended lifts itself', function (): void {
    $node = valkeyNode();
    ['team' => $team, 'project' => $project] = rollbackProject();
    $valkey = valkeyIn($team['workspace'], $node);
    attachValkey($valkey, $project);
    $lock = issuedValkeyCommand($valkey, AgentCommandType::LockValkey, source: ValkeyLockSource::Suspension);

    // The project is active again when the node reports the lock.
    completeValkeyCommand($node, $lock, valkeyResultFor($valkey, AgentCommandType::LockValkey));

    expect($valkey->fresh()->status)->toBe(ValkeyStatus::Locked)
        ->and(valkeyCommand($valkey, AgentCommandType::UnlockValkey)->status)->toBe(AgentCommandStatus::Pending);
});

test('deleting an account deletes the stores of its personal workspace', function (): void {
    $node = valkeyNode();
    $user = User::factory()->create();
    $personal = app(CreatePersonalWorkspaceAction::class)->handle($user);
    $valkey = valkeyIn($personal, $node);

    app(DeleteAccountAction::class)->handle($user);

    expect($valkey->fresh()?->status ?? ManagedValkey::withTrashed()->find($valkey->id)->status)->toBe(ValkeyStatus::Deleting)
        ->and(valkeyCommand($valkey, AgentCommandType::DeleteValkey)->status)->toBe(AgentCommandStatus::Pending);
});
