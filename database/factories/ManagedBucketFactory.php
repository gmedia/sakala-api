<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BucketStatus;
use App\Models\AgentNode;
use App\Models\ManagedBucket;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ManagedBucket>
 */
class ManagedBucketFactory extends Factory
{
    protected $model = ManagedBucket::class;

    /** Quota of a factory bucket, in MB. */
    private const QUOTA_MB = 10;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->bothify('bucket-####'),
            'region' => null,
            'status' => BucketStatus::Ready,
            'node_id' => AgentNode::factory(),
            's3_bucket' => ManagedBucket::S3_NAME_PREFIX.Str::lower(Str::random(ManagedBucket::S3_NAME_RANDOM_LENGTH)),
            'access_key_id' => ManagedBucket::ACCESS_KEY_PREFIX.Str::upper(Str::random(ManagedBucket::ACCESS_KEY_RANDOM_LENGTH)),
            'secret_access_key' => Str::random(ManagedBucket::SECRET_LENGTH),
            'quota_mb' => self::QUOTA_MB,
            'reserved_quota_mb' => self::QUOTA_MB,
            'size_bytes' => null,
            'usage_checked_at' => null,
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function onNode(AgentNode $node): static
    {
        return $this->state(fn (): array => ['node_id' => $node->id, 'region' => $node->effectiveRegion()]);
    }

    /** Both quota columns at once, as a settled bucket has them. */
    public function quota(int $mb): static
    {
        return $this->state(fn (): array => ['quota_mb' => $mb, 'reserved_quota_mb' => $mb]);
    }
}
