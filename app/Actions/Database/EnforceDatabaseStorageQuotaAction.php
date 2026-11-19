<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Data\Database\DatabaseStorageSweepData;
use App\Enums\AgentCommandType;
use App\Enums\DatabaseStatus;
use App\Enums\NotificationCategory;
use App\Models\ManagedDatabase;
use App\Models\Workspace;
use App\Services\Database\DatabaseCommandService;
use App\Services\Notification\UserNotifier;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\Database\DatabaseAudit;
use App\Support\Database\DatabaseSize;
use Illuminate\Support\Facades\DB;

/**
 * The storage quota of managed databases (ADR-020). PostgreSQL has no
 * per-database quota, so this sweep compares the sizes the database nodes
 * report with each workspace's `max_database_storage_mb`:
 *
 * - at WARNING_PERCENT or more, owners and members are told once, until usage
 *   drops below it again;
 * - at the quota or more, every ready database of the workspace is locked
 *   (its role turns NOLOGIN on the node, backups still run), and owners and
 *   members are told;
 * - below the quota, every locked database is unlocked. A raised quota counts
 *   the same as a smaller usage.
 *
 * A database with an open lock or unlock waits for it; the next run asks
 * again for one that failed. Each workspace is handled under its row lock,
 * like creating a database, so a creation cannot slip between the sum and the
 * locks.
 */
final class EnforceDatabaseStorageQuotaAction
{
    /** Share of the storage quota at which owners and members are warned. */
    public const WARNING_PERCENT = 80;

    private const PERCENT = 100;

    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly DatabaseCommandService $commands,
        private readonly UserNotifier $notifier,
    ) {}

    public function handle(): DatabaseStorageSweepData
    {
        $sweep = new DatabaseStorageSweepData;

        $workspaceIds = ManagedDatabase::query()
            ->whereNotNull('workspace_id')
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            DB::transaction(fn () => $this->enforce((string) $workspaceId, $sweep));
        }

        return $sweep;
    }

    private function enforce(string $workspaceId, DatabaseStorageSweepData $sweep): void
    {
        $workspace = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();

        if ($workspace === null) {
            return;
        }

        $limitMb = $this->limits->limitsFor($workspace)->max_database_storage_mb;
        $limitBytes = DatabaseSize::bytesOf($limitMb);
        $usedBytes = ManagedDatabase::storageBytesIn($workspace->id);
        $full = $usedBytes >= $limitBytes;

        $this->warn($workspace, $usedBytes, $limitMb, $limitBytes, $full, $sweep);

        $databases = ManagedDatabase::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', DatabaseStatus::provisionedCases())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $locked = 0;

        foreach ($databases as $database) {
            if ($full && $database->status === DatabaseStatus::Ready && $this->idle($database)) {
                $command = $this->commands->lock($database);
                DatabaseAudit::record(DatabaseAudit::STORAGE_SWEEP_ACTOR, DatabaseAudit::LOCK_REQUESTED, $database, [
                    'command_id' => $command->id,
                    'used_bytes' => $usedBytes,
                    'limit_mb' => $limitMb,
                ]);
                $locked++;
            }

            if (! $full && $database->status === DatabaseStatus::Locked && $this->idle($database)) {
                $command = $this->commands->unlock($database);
                DatabaseAudit::record(DatabaseAudit::STORAGE_SWEEP_ACTOR, DatabaseAudit::UNLOCK_REQUESTED, $database, [
                    'command_id' => $command->id,
                    'used_bytes' => $usedBytes,
                    'limit_mb' => $limitMb,
                ]);
                $sweep->unlockRequested++;
            }
        }

        if ($locked > 0) {
            $sweep->lockRequested += $locked;
            $this->tellLocked($workspace, $usedBytes, $limitMb);
        }
    }

    /**
     * Once per crossing of WARNING_PERCENT: `database_storage_warned_at` is
     * set when the notice goes out and cleared when usage drops below it. A
     * workspace that is already full only gets the lock notice.
     */
    private function warn(Workspace $workspace, int $usedBytes, int $limitMb, int $limitBytes, bool $full, DatabaseStorageSweepData $sweep): void
    {
        $over = $usedBytes * self::PERCENT >= $limitBytes * self::WARNING_PERCENT;

        if (! $over) {
            if ($workspace->database_storage_warned_at !== null) {
                $workspace->forceFill(['database_storage_warned_at' => null])->save();
            }

            return;
        }

        if ($workspace->database_storage_warned_at !== null) {
            return;
        }

        $workspace->forceFill(['database_storage_warned_at' => now()])->save();

        if ($full) {
            return;
        }

        $sweep->warned++;

        DatabaseAudit::recordForWorkspace(DatabaseAudit::STORAGE_WARNING, $workspace, [
            'used_bytes' => $usedBytes,
            'limit_mb' => $limitMb,
        ]);

        foreach ($workspace->projectManagers() as $user) {
            $this->notifier->send(
                user: $user,
                category: NotificationCategory::Workspace,
                title: 'Penyimpanan database hampir penuh',
                message: sprintf(
                    'Database di workspace %s sudah memakai %s dari kuota %d MB. Saat penuh, semua database di workspace ini dikunci sampai pemakaiannya turun atau kuotanya dinaikkan.',
                    $workspace->name,
                    DatabaseSize::megabytes($usedBytes),
                    $limitMb,
                ),
                workspaceId: $workspace->id,
            );
        }
    }

    /**
     * Applications can no longer connect, so the notice ignores the category
     * preference, like a suspension.
     */
    private function tellLocked(Workspace $workspace, int $usedBytes, int $limitMb): void
    {
        foreach ($workspace->projectManagers() as $user) {
            $this->notifier->send(
                user: $user,
                category: NotificationCategory::Workspace,
                title: 'Database dikunci karena penyimpanan penuh',
                message: sprintf(
                    'Database di workspace %s sudah memakai %s dari kuota %d MB, jadi semua database di workspace ini dikunci dan aplikasi tidak bisa terhubung. Hapus database yang tidak dipakai, atau minta admin menaikkan kuota.',
                    $workspace->name,
                    DatabaseSize::megabytes($usedBytes),
                    $limitMb,
                ),
                mandatory: true,
                workspaceId: $workspace->id,
            );
        }
    }

    /** No lock or unlock is on its way for the database. */
    private function idle(ManagedDatabase $database): bool
    {
        return $database->openCommand(AgentCommandType::LockDatabase) === null
            && $database->openCommand(AgentCommandType::UnlockDatabase) === null;
    }
}
