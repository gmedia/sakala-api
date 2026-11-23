<?php

declare(strict_types=1);

namespace App\Support\Attachment;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketKeyRotationStatus;
use App\Enums\DatabasePasswordRotationStatus;
use App\Enums\DeploymentTrigger;
use App\Models\AgentCommand;
use App\Models\User;
use App\Support\Bucket\BucketCommandFailure;

/**
 * The control-plane side of a credential rotation command, a
 * RotateDatabasePassword (ADR-020) or a RotateBucketKey (ADR-021), kept in its
 * `request_context` (never sent to the node): who asked, whether attached
 * projects are redeployed once the node reports success, and, once the API
 * queued them, what happened per project. Also what `password_rotation` on
 * DatabaseResource and `key_rotation` on BucketResource show. One
 * implementation serves both resources.
 */
final class CredentialRotation
{
    public const ACTOR_TYPE_KEY = 'actor_type';

    public const ACTOR_ID_KEY = 'actor_id';

    public const REDEPLOY_ATTACHED_KEY = 'redeploy_attached';

    /** When the API finished queueing the redeploys; absent until then. */
    public const REDEPLOYS_QUEUED_AT_KEY = 'redeploys_queued_at';

    /** One item per project attached at that moment: `project_id`, `deployment_id`, `skipped_reason`. */
    public const REDEPLOYS_KEY = 'redeploys';

    /** Which send of the same new bucket secret this command is, from 1; absent on the first. */
    public const ATTEMPT_KEY = 'attempt';

    /**
     * Sends of one bucket key rotation, the first included, before the API
     * stops resending after uncertain outcomes and shows `unknown`.
     */
    public const MAX_UNCERTAIN_ATTEMPTS = 10;

    /** Delay before the second send of a rotation; it doubles for each later send. */
    public const RESEND_BASE_DELAY_SECONDS = 15;

    /** Longest delay between two sends of a rotation. */
    public const RESEND_MAX_DELAY_SECONDS = 600;

    /** Prefix of the idempotency key of each redeploy after a database password rotation, followed by the command ID. */
    private const DATABASE_IDEMPOTENCY_PREFIX = 'database-password-rotation:';

    /** Prefix of the idempotency key of each redeploy after a bucket key rotation, followed by the command ID. */
    private const BUCKET_IDEMPOTENCY_PREFIX = 'bucket-key-rotation:';

    /** Whether the command is one of the rotations this class describes. */
    public static function isRotation(AgentCommand $command): bool
    {
        return in_array($command->type, [AgentCommandType::RotateDatabasePassword, AgentCommandType::RotateBucketKey], true);
    }

    /** The trigger of the redeploys a rotation queues. */
    public static function trigger(AgentCommand $command): DeploymentTrigger
    {
        return $command->type === AgentCommandType::RotateBucketKey
            ? DeploymentTrigger::BucketKeyRotated
            : DeploymentTrigger::DatabasePasswordRotated;
    }

    /** @return array<string, mixed> */
    public static function requestContext(User $actor, bool $redeployAttached): array
    {
        return [
            self::ACTOR_TYPE_KEY => User::class,
            self::ACTOR_ID_KEY => (string) $actor->id,
            self::REDEPLOY_ATTACHED_KEY => $redeployAttached,
        ];
    }

    /** Which send of the same new secret the command is, from 1. */
    public static function attempt(AgentCommand $command): int
    {
        $attempt = $command->request_context[self::ATTEMPT_KEY] ?? 1;

        return is_int($attempt) && $attempt > 0 ? $attempt : 1;
    }

    /**
     * Whether a closed bucket key rotation ended without knowing if the node
     * switched to the new secret: a first send that failed with an uncertain
     * code (BucketCommandFailure::uncertainRotationCodes()), or any send after
     * the first that did not complete, since an earlier send may have applied
     * the new secret already.
     */
    public static function uncertain(AgentCommand $command): bool
    {
        return $command->type === AgentCommandType::RotateBucketKey
            && in_array($command->status, [AgentCommandStatus::Failed, AgentCommandStatus::Expired, AgentCommandStatus::Cancelled], true)
            && (self::attempt($command) > 1 || in_array((string) $command->error_code, BucketCommandFailure::uncertainRotationCodes(), true));
    }

    /** Seconds to wait before send number `$attempt` (from 2): doubling from RESEND_BASE_DELAY_SECONDS, capped. */
    public static function resendDelaySeconds(int $attempt): int
    {
        $exponent = max(0, $attempt - 2);

        return (int) min(self::RESEND_MAX_DELAY_SECONDS, self::RESEND_BASE_DELAY_SECONDS * (2 ** $exponent));
    }

    public static function redeploysAttached(AgentCommand $command): bool
    {
        return ($command->request_context[self::REDEPLOY_ATTACHED_KEY] ?? false) === true;
    }

    public static function redeploysQueued(AgentCommand $command): bool
    {
        return isset($command->request_context[self::REDEPLOYS_QUEUED_AT_KEY]);
    }

    /** The user who asked for the rotation, if the account still exists. */
    public static function requester(AgentCommand $command): ?User
    {
        $context = $command->request_context ?? [];

        if (($context[self::ACTOR_TYPE_KEY] ?? null) !== User::class || ! is_string($context[self::ACTOR_ID_KEY] ?? null)) {
            return null;
        }

        return User::query()->find($context[self::ACTOR_ID_KEY]);
    }

    /**
     * Same key for the same project and rotation, so queueing again returns
     * the deployment already created instead of a second one.
     */
    public static function idempotencyKey(AgentCommand $command): string
    {
        $prefix = $command->type === AgentCommandType::RotateBucketKey
            ? self::BUCKET_IDEMPOTENCY_PREFIX
            : self::DATABASE_IDEMPOTENCY_PREFIX;

        return $prefix.$command->id;
    }

    /**
     * `password_rotation` of DatabaseResource or `key_rotation` of
     * BucketResource for the newest rotation, or null when the credential was
     * never rotated.
     *
     * @return array{status: DatabasePasswordRotationStatus|BucketKeyRotationStatus, requested_at: string, completed_at: string|null, failed_at: string|null, redeploy_attached: bool, redeploys: list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>|null}|null
     */
    public static function present(?AgentCommand $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        $status = $latest->type === AgentCommandType::RotateBucketKey
            ? BucketKeyRotationStatus::fromCommandStatus($latest->status, self::uncertain($latest))
            : DatabasePasswordRotationStatus::fromCommandStatus($latest->status);

        return [
            'status' => $status,
            'requested_at' => $latest->created_at->toAtomString(),
            'completed_at' => $latest->status === AgentCommandStatus::Succeeded ? $latest->completed_at?->toAtomString() : null,
            'failed_at' => ! $latest->status->isOpen() && $latest->status !== AgentCommandStatus::Succeeded && ! self::uncertain($latest) ? ($latest->failed_at ?? $latest->updated_at)->toAtomString() : null,
            'redeploy_attached' => self::redeploysAttached($latest),
            'redeploys' => self::redeploysQueued($latest) ? self::redeploys($latest) : null,
        ];
    }

    /** @return list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}> */
    private static function redeploys(AgentCommand $command): array
    {
        $items = $command->request_context[self::REDEPLOYS_KEY] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $redeploys = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['project_id'] ?? null)) {
                continue;
            }

            $redeploys[] = [
                'project_id' => $item['project_id'],
                'deployment_id' => is_string($item['deployment_id'] ?? null) ? $item['deployment_id'] : null,
                'skipped_reason' => is_string($item['skipped_reason'] ?? null) ? $item['skipped_reason'] : null,
            ];
        }

        return $redeploys;
    }
}
