<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Database;

use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Support\Attachment\CredentialRotation;
use App\Support\Database\DatabaseCommandFailure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A managed database as members of its workspace see it. `connection` holds
 * the non-secret part of the connection details; the password and the URL
 * that contains it are only in the audited credentials endpoint.
 *
 * @mixin ManagedDatabase
 *
 * @property ManagedDatabase $resource
 */
final class DatabaseResource extends JsonResource
{
    /** Relations every response of this resource needs, so a list costs no query per database. */
    public const RELATIONS = ['projects:id,name,slug', 'latestCommand', 'latestPasswordRotation', 'node:id,metadata'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $database = $this->resource;

        return [
            'id' => $database->id,
            'name' => $database->name,
            'engine' => $database->engine,
            /**
             * `provisioning` until the database node creates it, then `ready`. `locked` while the workspace is at its
             * storage quota: applications cannot connect until usage is under it again. `failed` when creation failed;
             * `deleting` while the node drops it. A dropped database is gone from the API.
             */
            'status' => $database->status,
            /** Region of the database node; `null` when that node has none. Projects attach only within one region. */
            'region' => $database->region,
            /** Size from the database node's last report, in bytes; `null` until it reports one. */
            'size_bytes' => $database->size_bytes,
            /** When that size was reported. */
            'size_checked_at' => $database->size_checked_at?->toAtomString(),
            /**
             * Where applications connect, without the password: the same host, port, database, and username attached
             * projects receive as `DB_HOST`, `DB_PORT`, `DB_DATABASE`, and `DB_USERNAME`. The host is on the database
             * node's private network, reachable only from Sakala workloads. `null` unless the status is `ready` or
             * `locked` and the node has reported its address. The password and `DATABASE_URL` are only in the
             * credentials endpoint.
             */
            'connection' => $this->connection($database),
            /** Projects the database is attached to. Each receives the connection variables from its next deployment. */
            'projects' => $database->projects
                ->sortBy('name')
                ->map(static fn (Project $project): array => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug])
                ->values()
                ->all(),
            /**
             * Set while the newest request to the database node for this database failed or expired: `command` (the agent
             * command type), `code` (from the agent, such as `database_extension_unavailable` or
             * `database_admin_unavailable`), `user_fixable` (`true` only when the user can fix it), `summary` in
             * Indonesian, and `failed_at`. `null` otherwise, including once a later request succeeds or is on its way.
             *
             * @var array{command: string, code: string, user_fixable: bool, summary: string, failed_at: string|null}|null
             */
            'last_failure' => DatabaseCommandFailure::present($database->latestCommand),
            /**
             * The newest password rotation; `null` when the password was never rotated. `status` is `pending` while
             * the database node has not reported, `completed` once the new password is the only one that connects,
             * and `failed` when the node failed it or did not finish in time (the old password still works; see
             * `last_failure`). The node switches as soon as it runs the request, so a `pending` rotation can
             * already have cut off the old password. `requested_at` is when it was asked for, `completed_at` and
             * `failed_at` are set only in their status. `redeploy_attached` is what the request asked for.
             * `redeploys` is `null` until the API has queued the redeploys after completion (and always without
             * `redeploy_attached`), then one item per project attached at that moment: `deployment_id` of the queued
             * deployment (trigger `database_password_rotated`), or `skipped_reason` when none was queued:
             * `no_active_deployment`, `requester_cannot_deploy`, `legal_acceptance_required`, `project_suspended`,
             * `active_deployment_limit`, `active_container_limit`, `build_rate_limit`, `repository_access_revoked`,
             * `database_endpoint_unavailable`, `database_not_ready`, `bucket_endpoint_unavailable`, `bucket_not_ready`, `bucket_key_rotating`, `release_command_unsupported`, or `failed`.
             *
             * @var array{status: 'pending'|'completed'|'failed', requested_at: string, completed_at: string|null, failed_at: string|null, redeploy_attached: bool, redeploys: list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>|null}|null
             */
            'password_rotation' => CredentialRotation::present($database->latestPasswordRotation),
            'created_at' => $database->created_at?->toAtomString(),
            'updated_at' => $database->updated_at?->toAtomString(),
        ];
    }

    /** @return array{host: string, port: int, database: string, username: string}|null */
    private function connection(ManagedDatabase $database): ?array
    {
        $endpoint = $database->status->isProvisioned() ? $database->node?->databaseEndpoint() : null;

        if ($endpoint === null) {
            return null;
        }

        return [
            'host' => $endpoint->host,
            'port' => $endpoint->port,
            'database' => $database->pg_database,
            'username' => $database->pg_role,
        ];
    }
}
