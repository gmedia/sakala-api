<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Data\Edge\EdgeHealthData;
use App\Enums\EdgeHealthReason;
use App\Models\EdgeToken;
use Carbon\CarbonImmutable;

/**
 * Judges one Edge instance from what its token recorded. Used by
 * `edge:check-health` and the admin Edge list, so both always agree.
 *
 * An Edge is unhealthy when any condition has held, continuously, for longer
 * than `sakala.edge.stale_after_seconds`:
 *
 * - stale: not seen since `last_used_at`;
 * - status: a known status other than `ok`, since `status_since`;
 * - revision behind: a known applied revision (`none` included) other than
 *   the current one, since `revision_mismatch_since`, which only
 *   `edge:check-health` sets. An unknown revision (an Edge too old to send
 *   it) leaves only the stale rule.
 *
 * Exactly the threshold is still healthy. Revoked tokens and tokens never
 * seen are unknown.
 */
final class EvaluateEdgeHealthAction
{
    public const DEFAULT_STALE_AFTER_SECONDS = 300;

    public function handle(EdgeToken $token, string $currentRevision, ?CarbonImmutable $now = null): EdgeHealthData
    {
        if ($token->isRevoked() || $token->last_used_at === null) {
            return EdgeHealthData::unknown();
        }

        $now ??= now()->toImmutable();
        $threshold = (int) config('sakala.edge.stale_after_seconds');
        $reasons = [];
        $unhealthySince = null;

        foreach ($this->conditions($token, $currentRevision, $now) as [$reason, $since]) {
            $crossedAt = $since->addSeconds($threshold);

            if (! $crossedAt->lt($now)) {
                continue;
            }

            $reasons[] = $reason;
            $unhealthySince = $unhealthySince === null || $crossedAt->lt($unhealthySince) ? $crossedAt : $unhealthySince;
        }

        return new EdgeHealthData($reasons === [], $reasons, $unhealthySince);
    }

    /** Whether the Edge reported an applied revision, `none` included, that is not the current one. */
    public static function isRevisionBehind(EdgeToken $token, string $currentRevision): bool
    {
        return $token->last_applied_revision !== null && $token->last_applied_revision !== $currentRevision;
    }

    /**
     * The conditions that hold now, each with the moment it started. A start
     * the row does not hold yet (the health check has not seen a mismatch)
     * counts from now.
     *
     * @return list<array{EdgeHealthReason, CarbonImmutable}>
     */
    private function conditions(EdgeToken $token, string $currentRevision, CarbonImmutable $now): array
    {
        /** @var CarbonImmutable $lastSeenAt */
        $lastSeenAt = $token->last_used_at;
        $conditions = [[EdgeHealthReason::Stale, $lastSeenAt]];

        if ($token->last_status !== null && $token->last_status !== EdgeToken::STATUS_OK) {
            $conditions[] = [EdgeHealthReason::Status, $token->status_since ?? $now];
        }

        if (self::isRevisionBehind($token, $currentRevision)) {
            $conditions[] = [EdgeHealthReason::RevisionBehind, $token->revision_mismatch_since ?? $now];
        }

        return $conditions;
    }
}
