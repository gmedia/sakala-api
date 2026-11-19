<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseConnectionEnvironment;
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
        ];
    }
}
