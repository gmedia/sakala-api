<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\ManagedValkey;
use App\Services\Valkey\ValkeyConnectionEnvironment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The managed Valkey store attached to a project, as the project page shows
 * it: which variables it sets and which the project overrides with its own.
 *
 * @mixin ManagedValkey
 *
 * @property ManagedValkey $resource
 */
final class ProjectValkeyResource extends JsonResource
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
        $overridden = app(ValkeyConnectionEnvironment::class)->overridden($this->projectKeys);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'mode' => $this->resource->mode,
            'status' => $this->resource->status,
            /**
             * Variables the next deployment receives from the store, values hidden: those the project does not set
             * itself, out of `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD`.
             *
             * @var list<string>
             */
            'variables' => array_values(array_diff(ValkeyConnectionEnvironment::names(), $overridden)),
            /**
             * Of those names, the ones the project sets as its own environment variables. The project's value wins,
             * so the console should warn about each. The project still joins the store's network.
             *
             * @var list<string>
             */
            'overridden_variables' => $overridden,
        ];
    }
}
