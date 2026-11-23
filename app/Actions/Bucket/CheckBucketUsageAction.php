<?php

declare(strict_types=1);

namespace App\Actions\Bucket;

use App\Enums\BucketStatus;
use App\Enums\NotificationCategory;
use App\Models\ManagedBucket;
use App\Services\Notification\UserNotifier;
use App\Support\Bucket\BucketAudit;
use App\Support\Database\DatabaseSize;
use Illuminate\Support\Facades\DB;

/**
 * Warns once per crossing when a bucket's reported usage reaches
 * USAGE_WARNING_PERCENT of its quota (ADR-021). There is no lock: the
 * node's hard quota refuses uploads at 100 %, so this only tells owners and
 * members before that happens. `storage_warned_at` is set when the notice
 * goes out and cleared once usage is below the share again.
 */
final class CheckBucketUsageAction
{
    /** Share of the quota at which owners and members are warned. */
    public const USAGE_WARNING_PERCENT = 80;

    private const PERCENT = 100;

    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    /** @return array{warned: int, cleared: int} */
    public function handle(): array
    {
        $result = ['warned' => 0, 'cleared' => 0];

        $ids = ManagedBucket::query()
            ->whereIn('status', BucketStatus::provisionedCases())
            ->whereNotNull('size_bytes')
            ->whereNotNull('workspace_id')
            ->pluck('id');

        foreach ($ids as $id) {
            $outcome = DB::transaction(fn (): ?string => $this->check((string) $id));

            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /** @return 'warned'|'cleared'|null */
    private function check(string $id): ?string
    {
        $bucket = ManagedBucket::query()->whereKey($id)->lockForUpdate()->first();

        if ($bucket === null || $bucket->size_bytes === null || ! $bucket->status->isProvisioned()) {
            return null;
        }

        $quotaBytes = DatabaseSize::bytesOf($bucket->quota_mb);
        $over = $bucket->size_bytes * self::PERCENT >= $quotaBytes * self::USAGE_WARNING_PERCENT;

        if (! $over) {
            if ($bucket->storage_warned_at === null) {
                return null;
            }

            $bucket->update(['storage_warned_at' => null]);

            return 'cleared';
        }

        if ($bucket->storage_warned_at !== null) {
            return null;
        }

        $bucket->update(['storage_warned_at' => now()]);

        BucketAudit::record(BucketAudit::USAGE_SWEEP_ACTOR, BucketAudit::STORAGE_WARNING, $bucket, [
            'size_bytes' => $bucket->size_bytes,
            'quota_mb' => $bucket->quota_mb,
        ]);

        $workspace = $bucket->workspace;

        if ($workspace === null) {
            return 'warned';
        }

        foreach ($workspace->projectManagers() as $user) {
            $this->notifier->send(
                user: $user,
                category: NotificationCategory::Workspace,
                title: 'Bucket hampir penuh',
                message: sprintf(
                    'Bucket %s di workspace %s sudah memakai %s dari kuota %d MB. Saat penuh, upload baru ditolak sampai ada berkas yang dihapus atau kuotanya dinaikkan.',
                    $bucket->name,
                    $workspace->name,
                    DatabaseSize::megabytes($bucket->size_bytes),
                    $bucket->quota_mb,
                ),
                workspaceId: $bucket->workspace_id,
            );
        }

        return 'warned';
    }
}
