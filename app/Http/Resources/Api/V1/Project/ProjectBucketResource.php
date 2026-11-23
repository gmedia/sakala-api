<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Models\ManagedBucket;
use App\Services\Bucket\BucketConnectionEnvironment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The managed bucket attached to a project, as the project page shows it:
 * which variables it sets and which the project overrides with its own.
 *
 * @mixin ManagedBucket
 *
 * @property ManagedBucket $resource
 */
final class ProjectBucketResource extends JsonResource
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
        $overridden = app(BucketConnectionEnvironment::class)->overridden($this->projectKeys);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'status' => $this->resource->status,
            'region' => $this->resource->region,
            /**
             * Variables the next deployment receives from the bucket, values hidden: those the project does not set
             * itself, out of `AWS_ENDPOINT_URL_S3`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`,
             * `AWS_DEFAULT_REGION`, `AWS_REGION`, `AWS_BUCKET`, `AWS_ACCESS_KEY_ID`, and `AWS_SECRET_ACCESS_KEY`.
             *
             * @var list<string>
             */
            'variables' => array_values(array_diff(BucketConnectionEnvironment::names(), $overridden)),
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
