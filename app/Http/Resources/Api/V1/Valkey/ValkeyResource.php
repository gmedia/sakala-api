<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Valkey;

use App\Models\ManagedValkey;
use App\Models\Project;
use App\Services\Valkey\ValkeyCommandService;
use App\Support\Attachment\CredentialRotation;
use App\Support\Valkey\ValkeyCommandFailure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A managed Valkey store as members of its workspace see it (ADR-023).
 * `connection` holds the non-secret part of how to reach it; the password
 * is only in the audited credentials endpoint. The node never appears.
 *
 * @mixin ManagedValkey
 *
 * @property ManagedValkey $resource
 */
final class ValkeyResource extends JsonResource
{
    /** Relations every response of this resource needs, so a list costs no query per store. */
    public const RELATIONS = ['projects:id,name,slug', 'latestCommand', 'latestPasswordRotation', 'openResize'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $valkey = $this->resource;

        return [
            'id' => $valkey->id,
            'name' => $valkey->name,
            /**
             * `cache`: no persistence, the least recently used keys are evicted when full, a restart empties it.
             * `persistent`: append-only file every second, writes are refused when full, backed up nightly. Fixed.
             */
            'mode' => $valkey->mode,
            /**
             * `provisioning` until the node creates it, then `ready`. `locked` while it is locked (abuse, or every
             * attached project is suspended): applications cannot log in, the data stays. `failed` when creation
             * failed; `deleting` while the node deletes it. A deleted store is gone from the API.
             */
            'status' => $valkey->status,
            /** Sakala region of the store's node; `null` when that node has none. */
            'region' => $valkey->region,
            /** `maxmemory` in MB the node applies, or will apply once creation finishes. */
            'maxmemory_mb' => $valkey->maxmemory_mb,
            /** The size of a resize on its way to the node, in MB; `null` when none is open. */
            'pending_maxmemory_mb' => $this->pendingMaxmemoryMb($valkey),
            /**
             * The node's last reading, all `null` until it reports one: `used_memory_bytes`, `evicted_keys` (keys
             * evicted since the store started, cache mode), `errorstat_oom` (writes refused because the store was
             * full, persistent mode), `connected_clients`, `aof_last_write_status` (`ok` or `err`, persistent mode
             * only), `container_status` (Docker state, or `missing`), `restart_count`, and `checked_at`.
             */
            'usage' => [
                'used_memory_bytes' => $valkey->used_memory_bytes,
                'evicted_keys' => $valkey->evicted_keys,
                'errorstat_oom' => $valkey->errorstat_oom,
                'connected_clients' => $valkey->connected_clients,
                'aof_last_write_status' => $valkey->aof_last_write_status,
                'container_status' => $valkey->container_status,
                'restart_count' => $valkey->restart_count,
                'checked_at' => $valkey->usage_checked_at?->toAtomString(),
            ],
            /**
             * Projects the store is attached to, ordered by name. Each joins the store's network and receives its
             * variables from its next deployment.
             */
            'projects' => $valkey->projects
                ->sortBy('name')
                ->map(static fn (Project $project): array => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug])
                ->values()
                ->all(),
            /**
             * How attached projects reach the store, without the password: `host` (`REDIS_HOST`, an address on the
             * store's private network, reachable only from attached projects) and `port` (`REDIS_PORT`, always
             * 6379). Visible to every member. `null` until the node created the store.
             *
             * @var array{host: string, port: int}|null
             */
            'connection' => $valkey->status->isProvisioned() && $valkey->host !== null && $valkey->port !== null
                ? ['host' => $valkey->host, 'port' => $valkey->port]
                : null,
            /**
             * Set while the newest request to the node for this store failed or expired: `command` (the agent
             * command type), `code` (from the agent, such as `valkey_capacity_exceeded`, `valkey_size_below_usage`,
             * or `valkey_in_use`, or the control plane's `command_lease_expired`), `user_fixable` (true for
             * `valkey_size_below_usage` and `valkey_in_use`), `summary` in Indonesian, and `failed_at`. `null`
             * otherwise, including once a later request succeeds or is on its way.
             *
             * @var array{command: string, code: string, user_fixable: bool, summary: string, failed_at: string|null}|null
             */
            'last_failure' => ValkeyCommandFailure::present($valkey->latestCommand),
            /**
             * The newest password rotation; `null` when it was never rotated. Same shape and meaning as
             * `key_rotation` of a bucket: `status` is `pending`, `completed`, `failed`, or `unknown` (the store
             * did not answer, so the change may apply when it starts again, and resending it did not complete:
             * deployments and credentials answer `409 VALKEY_PASSWORD_ROTATING` until a new rotation or
             * `valkeys:reconcile`). `redeploys` uses trigger `valkey_password_rotated`; `skipped_reason` adds
             * `valkey_not_ready`, `valkey_password_rotating`, and `valkey_node_mismatch` to the bucket list.
             *
             * @var array{status: 'pending'|'completed'|'failed'|'unknown', requested_at: string, completed_at: string|null, failed_at: string|null, redeploy_attached: bool, redeploys: list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>|null}|null
             */
            'password_rotation' => CredentialRotation::present($valkey->latestPasswordRotation),
            'created_at' => $valkey->created_at?->toAtomString(),
            'updated_at' => $valkey->updated_at?->toAtomString(),
        ];
    }

    private function pendingMaxmemoryMb(ManagedValkey $valkey): ?int
    {
        $command = $valkey->openResize;

        return $command === null ? null : app(ValkeyCommandService::class)->sizeOf($command);
    }
}
