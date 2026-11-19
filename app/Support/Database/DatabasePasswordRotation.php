<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Enums\DatabasePasswordRotationStatus;
use App\Models\AgentCommand;
use App\Models\User;

/**
 * The control-plane side of a RotateDatabasePassword command, kept in its
 * `request_context` (never sent to the node): who asked, whether attached
 * projects are redeployed once the node reports success, and, once the API
 * queued them, what happened per project. Also what `password_rotation` on
 * DatabaseResource shows.
 */
final class DatabasePasswordRotation
{
    public const ACTOR_TYPE_KEY = 'actor_type';

    public const ACTOR_ID_KEY = 'actor_id';

    public const REDEPLOY_ATTACHED_KEY = 'redeploy_attached';

    /** When the API finished queueing the redeploys; absent until then. */
    public const REDEPLOYS_QUEUED_AT_KEY = 'redeploys_queued_at';

    /** One item per project attached at that moment: `project_id`, `deployment_id`, `skipped_reason`. */
    public const REDEPLOYS_KEY = 'redeploys';

    /** Prefix of the idempotency key of each redeploy, followed by the command ID. */
    private const IDEMPOTENCY_PREFIX = 'database-password-rotation:';

    /** @return array<string, mixed> */
    public static function requestContext(User $actor, bool $redeployAttached): array
    {
        return [
            self::ACTOR_TYPE_KEY => User::class,
            self::ACTOR_ID_KEY => (string) $actor->id,
            self::REDEPLOY_ATTACHED_KEY => $redeployAttached,
        ];
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
        return self::IDEMPOTENCY_PREFIX.$command->id;
    }

    /**
     * `password_rotation` of DatabaseResource for the newest rotation, or null
     * for a database whose password was never rotated.
     *
     * @return array{status: DatabasePasswordRotationStatus, requested_at: string, completed_at: string|null, failed_at: string|null, redeploy_attached: bool, redeploys: list<array{project_id: string, deployment_id: string|null, skipped_reason: string|null}>|null}|null
     */
    public static function present(?AgentCommand $latest): ?array
    {
        if ($latest === null) {
            return null;
        }

        $status = DatabasePasswordRotationStatus::fromCommandStatus($latest->status);

        return [
            'status' => $status,
            'requested_at' => $latest->created_at->toAtomString(),
            'completed_at' => $status === DatabasePasswordRotationStatus::Completed ? $latest->completed_at?->toAtomString() : null,
            'failed_at' => $status === DatabasePasswordRotationStatus::Failed ? ($latest->failed_at ?? $latest->updated_at)->toAtomString() : null,
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
