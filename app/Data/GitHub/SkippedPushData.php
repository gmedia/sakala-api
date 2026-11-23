<?php

declare(strict_types=1);

namespace App\Data\GitHub;

use App\Enums\PushSkipReason;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;

/**
 * A push that did not deploy a project because nothing in it concerned the
 * project, or because auto-deploy is paused after a rollback (PushSkipReason).
 * Stored as an audit event on the project (`project.push_skipped`,
 * actor `github_app`), so it adds no deployment: no sequence number, nothing
 * counted against the build or deployment limits, no metrics, no
 * notification, and the project's latest deployment stays what it was.
 */
final readonly class SkippedPushData
{
    public const AUDIT_ACTION = 'project.push_skipped';

    /** How many changed paths the record keeps as examples. */
    public const MAX_EXAMPLE_PATHS = 5;

    /**
     * @param  list<string>  $examplePaths  At most MAX_EXAMPLE_PATHS of the changed paths, sorted.
     */
    public function __construct(
        public string $deliveryId,
        public PushSkipReason $reason,
        public string $commitSha,
        public ?string $commitMessage,
        public string $branch,
        public string $rootDirectory,
        public int $changedPathCount,
        public array $examplePaths,
        public string $afterDeploymentId,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * @param  list<string>  $changedPaths  Distinct and sorted.
     */
    public static function fromPush(
        string $deliveryId,
        PushSkipReason $reason,
        string $commitSha,
        ?string $commitMessage,
        string $branch,
        string $rootDirectory,
        array $changedPaths,
        string $afterDeploymentId,
    ): self {
        return new self(
            deliveryId: $deliveryId,
            reason: $reason,
            commitSha: $commitSha,
            commitMessage: $commitMessage,
            branch: $branch,
            rootDirectory: $rootDirectory,
            changedPathCount: count($changedPaths),
            examplePaths: array_slice($changedPaths, 0, self::MAX_EXAMPLE_PATHS),
            afterDeploymentId: $afterDeploymentId,
        );
    }

    public static function fromAuditEvent(AuditEvent $event): self
    {
        $metadata = $event->metadata ?? [];
        $examplePaths = $metadata['example_paths'] ?? [];

        return new self(
            deliveryId: (string) ($metadata['delivery_id'] ?? ''),
            reason: PushSkipReason::tryFrom((string) ($metadata['reason'] ?? '')) ?? PushSkipReason::NoChangesInRootDirectory,
            commitSha: (string) ($metadata['commit_sha'] ?? ''),
            commitMessage: is_string($metadata['commit_message'] ?? null) ? $metadata['commit_message'] : null,
            branch: (string) ($metadata['branch'] ?? ''),
            rootDirectory: (string) ($metadata['root_directory'] ?? ''),
            changedPathCount: (int) ($metadata['changed_path_count'] ?? 0),
            examplePaths: is_array($examplePaths) ? array_values(array_filter($examplePaths, is_string(...))) : [],
            afterDeploymentId: (string) ($metadata['after_deployment_id'] ?? ''),
            // The column is NOT NULL with a database default; the fallback only satisfies the nullable cast.
            createdAt: $event->created_at ?? CarbonImmutable::now(),
        );
    }

    /** @return array<string, mixed> */
    public function toMetadata(): array
    {
        return [
            'delivery_id' => $this->deliveryId,
            'reason' => $this->reason->value,
            'commit_sha' => $this->commitSha,
            'commit_message' => $this->commitMessage,
            'branch' => $this->branch,
            'root_directory' => $this->rootDirectory,
            'changed_path_count' => $this->changedPathCount,
            'example_paths' => $this->examplePaths,
            'after_deployment_id' => $this->afterDeploymentId,
        ];
    }
}
