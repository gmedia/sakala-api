<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Bucket;

use App\Models\ManagedBucket;
use App\Models\Project;
use App\Services\Bucket\BucketCommandService;
use App\Support\Attachment\CredentialRotation;
use App\Support\Bucket\BucketCommandFailure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A managed bucket as members of its workspace see it (ADR-021). `connection`
 * holds the non-secret part of how to reach it; the secret access key is only
 * in the audited credentials endpoint. The bucket name on the node, the
 * access key ID, and the node never appear outside `connection`.
 *
 * @mixin ManagedBucket
 *
 * @property ManagedBucket $resource
 */
final class BucketResource extends JsonResource
{
    /** Relations every response of this resource needs, so a list costs no query per bucket. */
    public const RELATIONS = ['projects:id,name,slug', 'latestCommand', 'latestKeyRotation', 'openQuotaChange', 'node:id,metadata'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $bucket = $this->resource;

        return [
            'id' => $bucket->id,
            'name' => $bucket->name,
            /**
             * `provisioning` until the storage node creates it, then `ready`. `locked` while an admin locked it: its
             * key is disabled, so applications cannot read or write and presigned URLs stop working. `failed` when
             * creation failed; `deleting` while the node deletes it. A deleted bucket is gone from the API.
             */
            'status' => $bucket->status,
            /** Sakala region of the storage node, not the S3 signing region (see `connection.region`); `null` when that node has none. */
            'region' => $bucket->region,
            /** Hard quota in MB the node applies, or will apply once creation finishes. */
            'quota_mb' => $bucket->quota_mb,
            /** The quota of a change on its way to the node, in MB; `null` when none is open. */
            'pending_quota_mb' => $this->pendingQuotaMb($bucket),
            /** Usage from the storage node's last report, in bytes; `null` until it reports one. */
            'size_bytes' => $bucket->size_bytes,
            /** When that usage was reported. */
            'usage_checked_at' => $bucket->usage_checked_at?->toAtomString(),
            /**
             * Projects the bucket is attached to, ordered by name. Each receives the bucket variables from its next
             * deployment.
             */
            'projects' => $bucket->projects
                ->sortBy('name')
                ->map(static fn (Project $project): array => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug])
                ->values()
                ->all(),
            /**
             * How applications reach the bucket, without the secret: `endpoint` (the public S3 endpoint, the value of
             * `AWS_ENDPOINT_URL_S3` and `AWS_ENDPOINT`), `region` (the S3 signing region, `AWS_DEFAULT_REGION` and
             * `AWS_REGION`), `bucket` (`AWS_BUCKET`), `access_key_id` (`AWS_ACCESS_KEY_ID`), and `path_style`, always
             * `true`. Visible to every member, viewers included: none of it is secret. `null` unless the status is
             * `ready` or `locked` and the node has reported its endpoint.
             */
            'connection' => $this->connection($bucket),
            /**
             * Set while the newest request to the storage node for this bucket failed or expired: `command` (the
             * agent command type), `code` (from the agent, such as `bucket_capacity_exceeded` or
             * `bucket_admin_unavailable`, or the control plane's `command_lease_expired`), `user_fixable` (always
             * `false` in this version), `summary` in Indonesian, and `failed_at`. `null` otherwise, including once a
             * later request succeeds or is on its way.
             *
             * @var array{command: string, code: string, user_fixable: bool, summary: string, failed_at: string|null}|null
             */
            'last_failure' => BucketCommandFailure::present($bucket->latestCommand),
            /**
             * The newest key rotation; `null` when the key was never rotated. `status` is `pending` while the storage
             * node has not reported, `completed` once only the new secret works, and `failed` when the node failed it
             * definitively (the old secret still works; see `last_failure`), and `unknown` when the node could not
             * confirm the outcome even after the API resent the same secret several times: neither secret is known to
             * work, and a new rotation settles it. While the outcome is unconfirmed the API resends the same new
             * secret and the status stays `pending`. The node switches as soon
             * as it runs the request, so a `pending` rotation can already have cut off the old secret. The access key
             * ID never changes. `requested_at` is when it was asked for, `completed_at` and `failed_at` are set only
             * in their status. `redeploy_attached` is what the request asked for. `redeploys` is `null` until the API
             * has queued the redeploys after completion (and always without `redeploy_attached`), then one item per
             * project attached at that moment: `deployment_id` of the queued deployment (trigger
             * `bucket_key_rotated`), or `skipped_reason` when none was queued: `no_active_deployment`,
             * `requester_cannot_deploy`, `legal_acceptance_required`, `project_suspended`, `active_deployment_limit`,
             * `active_container_limit`, `build_rate_limit`, `repository_access_revoked`,
             * `database_endpoint_unavailable`, `database_not_ready`, `bucket_endpoint_unavailable`,
             * `bucket_not_ready`, `release_command_unsupported`, or `failed`.
             *
             * @var array{status: 'pending'|'completed'|'failed'|'unknown', requested_at: string, completed_at: string|null, failed_at: string|null, redeploy_attached: bool, redeploys: list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>|null}|null
             */
            'key_rotation' => CredentialRotation::present($bucket->latestKeyRotation),
            'created_at' => $bucket->created_at?->toAtomString(),
            'updated_at' => $bucket->updated_at?->toAtomString(),
        ];
    }

    private function pendingQuotaMb(ManagedBucket $bucket): ?int
    {
        $command = $bucket->openQuotaChange;

        return $command === null ? null : app(BucketCommandService::class)->quotaOf($command);
    }

    /** @return array{endpoint: string, region: string, bucket: string, access_key_id: string, path_style: true}|null */
    private function connection(ManagedBucket $bucket): ?array
    {
        $storage = $bucket->status->isProvisioned() ? $bucket->node?->objectStorage() : null;

        if ($storage === null) {
            return null;
        }

        return [
            'endpoint' => $storage->endpoint,
            'region' => $storage->region,
            'bucket' => $bucket->s3_bucket,
            'access_key_id' => $bucket->access_key_id,
            'path_style' => true,
        ];
    }
}
