<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\AgentCommandType;
use App\Enums\ValkeyStatus;
use App\Models\ManagedValkey;
use App\Services\Valkey\ValkeyCommandService;
use Illuminate\Support\Facades\DB;

/**
 * Sends CreateValkey again, with the stored mode, size, and password, for
 * every ready or locked store (of one node, or one store), after
 * `valkey_not_found` or a rebuilt node (ADR-023). The API is the source of
 * truth for stores, modes, sizes, and passwords; backups hold only data.
 * CreateValkey never unlocks, so a locked store stays locked, and it settles
 * a password rotation that ended `unknown` by putting the stored password
 * back. A store with an open CreateValkey, RotateValkeyPassword, or
 * ResizeValkey is skipped: each would race with this one over the password
 * or the size.
 */
final class ReconcileValkeysAction
{
    public function __construct(
        private readonly ValkeyCommandService $commands,
    ) {}

    /** @return array{sent: int, skipped: int} */
    public function handle(?string $nodeId = null, ?string $valkeyId = null): array
    {
        $result = ['sent' => 0, 'skipped' => 0];

        $ids = ManagedValkey::query()
            ->whereIn('status', ValkeyStatus::provisionedCases())
            ->when($nodeId !== null, static fn ($query) => $query->where('node_id', $nodeId))
            ->when($valkeyId !== null, static fn ($query) => $query->whereKey($valkeyId))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $sent = DB::transaction(function () use ($id): bool {
                $valkey = ManagedValkey::query()->whereKey($id)->lockForUpdate()->first();

                if ($valkey === null
                    || ! $valkey->status->isProvisioned()
                    || $valkey->password === null
                    || $valkey->openCommand(AgentCommandType::CreateValkey) !== null
                    || $valkey->openCommand(AgentCommandType::RotateValkeyPassword) !== null
                    || $valkey->openCommand(AgentCommandType::ResizeValkey) !== null) {
                    return false;
                }

                $this->commands->create($valkey, $valkey->password);

                return true;
            });

            $result[$sent ? 'sent' : 'skipped']++;
        }

        return $result;
    }
}
