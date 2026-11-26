<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\NotificationCategory;
use App\Enums\ValkeyMode;
use App\Enums\ValkeyStatus;
use App\Models\ManagedValkey;
use App\Services\Notification\UserNotifier;
use App\Support\Database\DatabaseSize;
use App\Support\Valkey\ValkeyAudit;
use Illuminate\Support\Facades\DB;

/**
 * Warns once per crossing when a store's reported `used_memory` reaches
 * USAGE_WARNING_PERCENT of its `maxmemory` (ADR-023), like the bucket usage
 * sweep. There is no lock: `maxmemory` bounds the store on the node, so a
 * full cache store evicts keys and a full persistent store refuses writes;
 * this only tells owners and members before that happens.
 * `memory_warned_at` is set when the notice goes out and cleared once usage
 * is below the share again.
 */
final class CheckValkeyUsageAction
{
    /** Share of `maxmemory` at which owners and members are warned. */
    public const USAGE_WARNING_PERCENT = 80;

    private const PERCENT = 100;

    public function __construct(
        private readonly UserNotifier $notifier,
    ) {}

    /** @return array{warned: int, cleared: int} */
    public function handle(): array
    {
        $result = ['warned' => 0, 'cleared' => 0];

        $ids = ManagedValkey::query()
            ->whereIn('status', ValkeyStatus::provisionedCases())
            ->whereNotNull('used_memory_bytes')
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
        $valkey = ManagedValkey::query()->whereKey($id)->lockForUpdate()->first();

        if ($valkey === null || $valkey->used_memory_bytes === null || ! $valkey->status->isProvisioned()) {
            return null;
        }

        $over = $valkey->used_memory_bytes * self::PERCENT >= DatabaseSize::bytesOf($valkey->maxmemory_mb) * self::USAGE_WARNING_PERCENT;

        if (! $over) {
            if ($valkey->memory_warned_at === null) {
                return null;
            }

            $valkey->update(['memory_warned_at' => null]);

            return 'cleared';
        }

        if ($valkey->memory_warned_at !== null) {
            return null;
        }

        $valkey->update(['memory_warned_at' => now()]);

        ValkeyAudit::record(ValkeyAudit::USAGE_SWEEP_ACTOR, ValkeyAudit::MEMORY_WARNING, $valkey, [
            'used_memory_bytes' => $valkey->used_memory_bytes,
            'maxmemory_mb' => $valkey->maxmemory_mb,
        ]);

        $workspace = $valkey->workspace;

        if ($workspace === null) {
            return 'warned';
        }

        $consequence = $valkey->mode === ValkeyMode::Persistent
            ? 'Saat penuh, Valkey mode persisten menolak tulisan baru (termasuk job antrean) sampai ada data yang dihapus atau ukurannya diperbesar.'
            : 'Saat penuh, Valkey mode cache membuang key yang paling lama tidak dipakai.';

        foreach ($workspace->projectManagers() as $user) {
            $this->notifier->send(
                user: $user,
                category: NotificationCategory::Workspace,
                title: 'Valkey hampir penuh',
                message: sprintf(
                    'Valkey %s di workspace %s sudah memakai %s dari %d MB. %s',
                    $valkey->name,
                    $workspace->name,
                    DatabaseSize::megabytes($valkey->used_memory_bytes),
                    $valkey->maxmemory_mb,
                    $consequence,
                ),
                workspaceId: $valkey->workspace_id,
            );
        }

        return 'warned';
    }
}
