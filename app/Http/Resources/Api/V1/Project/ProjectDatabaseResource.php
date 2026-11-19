<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\DatabaseAttachment;
use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseConnectionEnvironment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The managed database attached to a project, as the project page shows it:
 * which variables it sets and which the project overrides with its own.
 *
 * @mixin ManagedDatabase
 *
 * @property ManagedDatabase $resource
 */
final class ProjectDatabaseResource extends JsonResource
{
    /** @var list<string> */
    private array $projectKeys = [];

    /** @param  list<string>  $keys  Names of the project's own environment variables. */
    public function withProjectKeys(array $keys): self
    {
        $this->projectKeys = $keys;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $overridden = app(DatabaseConnectionEnvironment::class)->overridden($this->projectKeys);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'status' => $this->resource->status,
            'region' => $this->resource->region,
            /**
             * Variables the next deployment receives from the database, values hidden: those the project does not
             * set itself, out of `DATABASE_URL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
             * and `DB_PASSWORD`.
             *
             * @var list<string>
             */
            'variables' => array_values(array_diff(DatabaseConnectionEnvironment::names(), $overridden)),
            /**
             * Of those names, the ones the project sets as its own environment variables. The project's value wins,
             * so the console should warn about each.
             *
             * @var list<string>
             */
            'overridden_variables' => $overridden,
            /**
             * The deployment asked for when the database was created for this project (`deploy_when_ready`), or
             * `null` without one. `status`: `waiting` while the database is being created, `started` once the
             * deployment exists (`deployment_id`), `skipped` when none was created (`skipped_reason`).
             * `skipped_reason` is one of `requester_cannot_deploy`, `legal_acceptance_required`, `project_suspended`,
             * `active_deployment_limit`, `active_container_limit`, `build_rate_limit`, `repository_access_revoked`,
             * `database_endpoint_unavailable`, `release_command_unsupported`, `database_provisioning_failed`, or
             * `failed`.
             */
            'deploy_when_ready' => $this->deployWhenReady(),
        ];
    }

    /** @return array{status: 'waiting'|'started'|'skipped', requested_at: string, deployment_id: string|null, skipped_reason: string|null}|null */
    private function deployWhenReady(): ?array
    {
        $pivot = $this->resource->relationLoaded('pivot') ? $this->resource->getRelation('pivot') : null;

        if (! $pivot instanceof Model) {
            return null;
        }

        // The pivot of Project::databases() carries the attachment's deploy columns.
        $attachment = (new DatabaseAttachment)->newFromBuilder($pivot->getAttributes());
        $status = $attachment->deployRequestStatus();

        if ($status === null || $attachment->deploy_requested_at === null) {
            return null;
        }

        return [
            'status' => $status->value,
            'requested_at' => $attachment->deploy_requested_at->toAtomString(),
            'deployment_id' => $attachment->deploy_deployment_id,
            'skipped_reason' => $attachment->deploy_skipped_reason,
        ];
    }
}
