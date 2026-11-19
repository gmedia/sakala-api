<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Project;

use App\Data\GitHub\SkippedPushData;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuditEvent $resource
 */
final class SkippedPushResource extends JsonResource
{
    /**
     * @return array{
     *     delivery_id: string,
     *     reason: 'no_changes_in_root_directory',
     *     commit_sha: string,
     *     commit_message: string|null,
     *     branch: string,
     *     root_directory: string,
     *     changed_path_count: int,
     *     example_paths: list<string>,
     *     after_deployment_id: string,
     *     created_at: string,
     * }
     */
    public function toArray(Request $request): array
    {
        $skip = SkippedPushData::fromAuditEvent($this->resource);
        /** @var CarbonImmutable $createdAt fromAuditEvent() always sets it. */
        $createdAt = $skip->createdAt;

        return [
            // The GitHub delivery (X-GitHub-Delivery) that was skipped; one record per delivery and project.
            'delivery_id' => $skip->deliveryId,
            // Why the push did not deploy. The only value: `no_changes_in_root_directory`.
            'reason' => $skip->reason->value,
            // The pushed commit (`after`), 40 hexadecimal characters.
            'commit_sha' => $skip->commitSha,
            // First line of the head commit's message, at most 500 characters; null without one.
            'commit_message' => $skip->commitMessage,
            'branch' => $skip->branch,
            // The project's root directory when the push arrived, normalized (`apps/web`, never empty).
            'root_directory' => $skip->rootDirectory,
            // Distinct files the push changed.
            'changed_path_count' => $skip->changedPathCount,
            // Up to 5 of those files, sorted; all of them belong to other projects.
            'example_paths' => $skip->examplePaths,
            // The project's newest deployment when the push arrived: the history shows the skip right above it.
            'after_deployment_id' => $skip->afterDeploymentId,
            'created_at' => $createdAt->toAtomString(),
        ];
    }
}
