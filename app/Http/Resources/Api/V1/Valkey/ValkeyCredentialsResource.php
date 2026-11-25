<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Valkey;

use App\Data\Valkey\ValkeyCredentialsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How to reach one managed Valkey store with its password: the same values
 * attached projects receive. Reachable only from attached projects.
 *
 * @property ValkeyCredentialsData $resource
 */
final class ValkeyCredentialsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Address of the store on its private network (`REDIS_HOST`). */
            'host' => $this->resource->host,
            /** Always 6379 (`REDIS_PORT`). */
            'port' => $this->resource->port,
            /** Password of the `default` user (`REDIS_PASSWORD`): letters and digits only. */
            'password' => $this->resource->password,
            /** `redis://:<password>@<host>:<port>`, without a database number (`REDIS_URL`). */
            'url' => $this->resource->url,
            /**
             * Exactly the variables an attached project receives, in this order: `REDIS_URL`, `REDIS_HOST`,
             * `REDIS_PORT`, `REDIS_PASSWORD`.
             */
            'variables' => $this->resource->variables,
        ];
    }
}
