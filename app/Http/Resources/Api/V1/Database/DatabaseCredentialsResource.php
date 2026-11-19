<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Database;

use App\Data\Database\DatabaseCredentialsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How to connect to one managed database. The same values attached projects
 * receive as `DATABASE_URL` and `DB_*`.
 *
 * @property DatabaseCredentialsData $resource
 */
final class DatabaseCredentialsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** Host of the database node as runtime nodes reach it, on its private network; `null` until the node reports it. */
            'host' => $this->resource->host,
            /** `null` until the node reports it. */
            'port' => $this->resource->port,
            'database' => $this->resource->database,
            'username' => $this->resource->username,
            'password' => $this->resource->password,
            /** `postgresql://username:password@host:port/database?sslmode=require`; `null` until the node reports its address. */
            'url' => $this->resource->url,
            /** Always `require`: the instance only accepts TLS. */
            'sslmode' => $this->resource->sslMode,
        ];
    }
}
