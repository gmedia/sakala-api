<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Enums\NotificationCategory;
use App\Models\AgentCommand;
use App\Models\AuditEvent;
use App\Models\ManagedDatabase;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const DATABASE_QUOTA_MB = 100;

function megabytes(float $megabytes): int
{
    return (int) round($megabytes * 1024 * 1024);
}

function sweepStorage(): void
{
    test()->artisan('databases:enforce-storage-quota')->assertSuccessful();
}

/** @return list<string> */
function notificationTitles(User $user): array
{
    return $user->fresh()->notifications()->get()->map(fn ($notification): string => (string) $notification->data['title'])->all();
}

function commandsOf(AgentCommandType $type): int
{
    return AgentCommand::query()->where('type', $type)->count();
}

test('below the warning share nothing happens', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(79.9)]);

    sweepStorage();

    expect(notificationTitles($team['owner']))->toBe([])
        ->and($team['workspace']->fresh()->database_storage_warned_at)->toBeNull()
        ->and(commandsOf(AgentCommandType::LockDatabase))->toBe(0);
});

test('at the warning share owners and members are told once, viewers never', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(50)]);
    databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(30)]);

    sweepStorage();
    sweepStorage();

    $audit = AuditEvent::query()->where('action', 'workspace.database_storage_warning')->sole();
    $notification = $team['member']->fresh()->notifications()->sole();

    expect(notificationTitles($team['owner']))->toBe(['Penyimpanan database hampir penuh'])
        ->and($notification->data['category'])->toBe(NotificationCategory::Workspace->value)
        ->and($notification->data['workspace_id'])->toBe($team['workspace']->id)
        ->and($notification->data['message'])->toBe("Database di workspace {$team['workspace']->name} sudah memakai 80,0 MB dari kuota 100 MB. Saat penuh, semua database di workspace ini dikunci sampai pemakaiannya turun atau kuotanya dinaikkan.")
        ->and(notificationTitles($team['viewer']))->toBe([])
        ->and($audit->subject_id)->toBe($team['workspace']->id)
        ->and(commandsOf(AgentCommandType::LockDatabase))->toBe(0);
});

test('the warning respects the workspace category preference', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    $team['member']->update(['notification_preferences' => ['workspace' => false]]);
    databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(90)]);

    sweepStorage();

    expect(notificationTitles($team['member']))->toBe([])
        ->and(notificationTitles($team['owner']))->toBe(['Penyimpanan database hampir penuh']);
});

test('dropping below the warning share and crossing it again warns again', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    $database = databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(85)]);

    sweepStorage();
    $database->update(['size_bytes' => megabytes(40)]);
    sweepStorage();

    expect($team['workspace']->fresh()->database_storage_warned_at)->toBeNull();

    $database->update(['size_bytes' => megabytes(90)]);
    sweepStorage();

    expect(notificationTitles($team['owner']))->toHaveCount(2);
});

test('at the quota every ready database of the workspace is locked, and people are told even with the category off', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    $team['owner']->update(['notification_preferences' => ['workspace' => false]]);
    $big = databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(99)]);
    $small = databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(1)]);
    $creating = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Provisioning]);
    $elsewhere = databaseIn(databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB])['workspace'], $node, ['size_bytes' => megabytes(1)]);

    sweepStorage();
    // A lock already on its way is not asked for twice.
    sweepStorage();

    $locks = AgentCommand::query()->where('type', AgentCommandType::LockDatabase)->get();

    expect($locks->pluck('database_id')->sort()->values()->all())->toBe(collect([$big->id, $small->id])->sort()->values()->all())
        ->and($locks->every(fn (AgentCommand $lock): bool => $lock->agent_node_id === $node->id && $lock->status === AgentCommandStatus::Pending))->toBeTrue()
        ->and(notificationTitles($team['owner']))->toBe(['Database dikunci karena penyimpanan penuh'])
        ->and(AuditEvent::query()->where('action', 'database.lock_requested')->count())->toBe(2)
        ->and(AgentCommand::query()->where('database_id', $creating->id)->where('type', AgentCommandType::LockDatabase)->exists())->toBeFalse()
        ->and(AgentCommand::query()->where('database_id', $elsewhere->id)->exists())->toBeFalse();

    // The node reports the locks done.
    $locks->each(function (AgentCommand $lock) use ($node): void {
        $lock->update(['status' => AgentCommandStatus::Running]);
        $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$lock->id}/complete", ['result' => null])->assertNoContent();
    });

    expect(databaseStatus($big))->toBe(DatabaseStatus::Locked)
        ->and(databaseStatus($small))->toBe(DatabaseStatus::Locked);
});

test('back under the quota, or with a raised quota, locked databases are unlocked', function (string $how): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    $database = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Locked, 'size_bytes' => megabytes(100)]);
    $other = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Locked, 'size_bytes' => 0]);

    sweepStorage();
    expect(commandsOf(AgentCommandType::UnlockDatabase))->toBe(0);

    match ($how) {
        'smaller' => $database->update(['size_bytes' => megabytes(99.9)]),
        'raised' => $team['workspace']->limitProfile->update(['max_database_storage_mb' => 200]),
    };

    sweepStorage();
    sweepStorage();

    expect(AgentCommand::query()->where('type', AgentCommandType::UnlockDatabase)->pluck('database_id')->sort()->values()->all())
        ->toBe(collect([$database->id, $other->id])->sort()->values()->all())
        ->and(AuditEvent::query()->where('action', 'database.unlock_requested')->count())->toBe(2);

    $unlock = databaseCommand($database, AgentCommandType::UnlockDatabase);
    $unlock->update(['status' => AgentCommandStatus::Running]);
    $this->withHeaders(databaseNodeHeaders($node))->postJson("/api/agent/v1/commands/{$unlock->id}/complete", ['result' => null])->assertNoContent();

    expect(databaseStatus($database))->toBe(DatabaseStatus::Ready);
})->with(['smaller', 'raised']);

test('a failed lock is asked for again on the next run', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    $database = databaseIn($team['workspace'], $node, ['size_bytes' => megabytes(120)]);

    sweepStorage();
    databaseCommand($database, AgentCommandType::LockDatabase)->update(['status' => AgentCommandStatus::Failed, 'failed_at' => now()]);
    sweepStorage();

    expect(commandsOf(AgentCommandType::LockDatabase))->toBe(2);
});

test('a database being deleted still counts towards the storage, and is neither locked nor unlocked', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => DATABASE_QUOTA_MB]);
    $deleting = databaseIn($team['workspace'], $node, ['status' => DatabaseStatus::Deleting, 'size_bytes' => megabytes(100)]);
    $ready = databaseIn($team['workspace'], $node, ['size_bytes' => 0]);

    sweepStorage();

    expect(AgentCommand::query()->where('database_id', $ready->id)->where('type', AgentCommandType::LockDatabase)->exists())->toBeTrue()
        ->and(AgentCommand::query()->where('database_id', $deleting->id)->exists())->toBeFalse();
});

test('a storage quota of zero locks every database of the workspace', function (): void {
    $node = databaseNode();
    $team = databaseTeam(['max_database_storage_mb' => 0]);
    databaseIn($team['workspace'], $node, ['size_bytes' => null]);

    sweepStorage();

    expect(commandsOf(AgentCommandType::LockDatabase))->toBe(1)
        ->and(notificationTitles($team['owner']))->toBe(['Database dikunci karena penyimpanan penuh']);
});

test('a workspace without a profile follows the global storage default', function (): void {
    config(['sakala.databases.default_max_storage_mb' => 10]);
    $node = databaseNode();
    $user = User::factory()->create();
    $workspace = $user->personalWorkspace()->firstOrFail();
    databaseIn($workspace, $node, ['size_bytes' => megabytes(10)]);

    sweepStorage();

    expect(commandsOf(AgentCommandType::LockDatabase))->toBe(1);
});

test('the sweep runs every minute on one server', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'databases:enforce-storage-quota'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(ManagedDatabase::query()->count())->toBe(0);
});
