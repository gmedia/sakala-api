<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Data\Edge\EdgeHealthCheckResultData;
use App\Data\Edge\EdgeHealthData;
use App\Enums\EdgeHealthReason;
use App\Enums\EdgeHealthTransition;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\EdgeToken;
use App\Models\User;
use App\Notifications\Edge\EdgeRecoveredNotification;
use App\Notifications\Edge\EdgeUnhealthyNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The `edge:check-health` sweep. For every token that is not revoked and was
 * seen at least once, under a row lock: keeps `revision_mismatch_since`
 * current, evaluates the Edge (EvaluateEdgeHealthAction), and compares the
 * result with the stored alert state (`unhealthy_since`, null while
 * healthy). Only a change of state writes an audit event and emails every
 * admin, so an Edge that stays unhealthy, even for other reasons, alerts
 * once, and its recovery once. Mail leaves after the commit.
 */
final class CheckEdgeHealthAction
{
    /** The `actor_id` of the `system` actor in the audit events. */
    public const ACTOR_ID = 'edge:check-health';

    public const UNHEALTHY_AUDIT_ACTION = 'edge.unhealthy';

    public const RECOVERED_AUDIT_ACTION = 'edge.recovered';

    public function __construct(
        private readonly GetEdgeRouteTableAction $routeTable,
        private readonly EvaluateEdgeHealthAction $evaluate,
    ) {}

    public function handle(): EdgeHealthCheckResultData
    {
        $currentRevision = $this->routeTable->handle()->revision;
        $ids = EdgeToken::query()
            ->whereNull('revoked_at')
            ->whereNotNull('last_used_at')
            ->orderBy('name')
            ->pluck('id');
        $checked = 0;
        $becameUnhealthy = 0;
        $recovered = 0;

        foreach ($ids as $id) {
            $change = $this->check((string) $id, $currentRevision);

            if ($change === null) {
                continue;
            }

            $checked++;

            if ($change === EdgeHealthTransition::BecameUnhealthy) {
                $becameUnhealthy++;
            } elseif ($change === EdgeHealthTransition::Recovered) {
                $recovered++;
            }
        }

        return new EdgeHealthCheckResultData($checked, $becameUnhealthy, $recovered);
    }

    /** Null when the token was revoked or is gone by the time it is locked. */
    private function check(string $id, string $currentRevision): ?EdgeHealthTransition
    {
        return DB::transaction(function () use ($id, $currentRevision): ?EdgeHealthTransition {
            $token = EdgeToken::query()->lockForUpdate()->find($id);

            if ($token === null || $token->isRevoked() || $token->last_used_at === null) {
                return null;
            }

            $now = now()->toImmutable();

            if (! EvaluateEdgeHealthAction::isRevisionBehind($token, $currentRevision)) {
                $token->revision_mismatch_since = null;
            } elseif ($token->revision_mismatch_since === null) {
                $token->revision_mismatch_since = $now;
            }

            $health = $this->evaluate->handle($token, $currentRevision, $now);
            $transition = EdgeHealthTransition::None;

            if ($health->isUnhealthy() && $token->unhealthy_since === null) {
                $token->unhealthy_since = $health->unhealthySince;
                $this->recordUnhealthy($token, $health, $currentRevision);
                $transition = EdgeHealthTransition::BecameUnhealthy;
            } elseif (! $health->isUnhealthy() && $token->unhealthy_since !== null) {
                $this->recordRecovered($token, $token->unhealthy_since, $now);
                $token->unhealthy_since = null;
                $transition = EdgeHealthTransition::Recovered;
            }

            $token->save();

            return $transition;
        });
    }

    private function recordUnhealthy(EdgeToken $token, EdgeHealthData $health, string $currentRevision): void
    {
        /** @var CarbonImmutable $since */
        $since = $health->unhealthySince;

        $this->audit(self::UNHEALTHY_AUDIT_ACTION, $token, [
            'name' => $token->name,
            'reasons' => array_map(static fn (EdgeHealthReason $reason): string => $reason->value, $health->reasons),
            'unhealthy_since' => $since->toISOString(),
            'status' => $token->last_status,
            'applied_revision' => $token->last_applied_revision,
            'current_revision' => $currentRevision,
            'last_seen_at' => $token->last_used_at?->toISOString(),
            'version' => $token->last_version,
        ]);

        $this->notifyAdmins(new EdgeUnhealthyNotification(
            edgeName: $token->name,
            unhealthySince: $since,
            reasons: $health->reasons,
            lastSeenAt: $token->last_used_at,
            status: $token->last_status,
            appliedRevision: $token->last_applied_revision,
            currentRevision: $currentRevision,
        ));
    }

    private function recordRecovered(EdgeToken $token, CarbonImmutable $unhealthySince, CarbonImmutable $now): void
    {
        $this->audit(self::RECOVERED_AUDIT_ACTION, $token, [
            'name' => $token->name,
            'unhealthy_since' => $unhealthySince->toISOString(),
            'recovered_at' => $now->toISOString(),
            'unhealthy_seconds' => (int) $unhealthySince->diffInSeconds($now, true),
        ]);

        $this->notifyAdmins(new EdgeRecoveredNotification($token->name, $unhealthySince, $now));
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(string $action, EdgeToken $token, array $metadata): void
    {
        AuditEvent::query()->create([
            'actor_type' => AuditEvent::SYSTEM_ACTOR_TYPE,
            'actor_id' => self::ACTOR_ID,
            'action' => $action,
            'subject_type' => EdgeToken::class,
            'subject_id' => $token->id,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Every admin, once. A failed send is reported and does not stop the
     * others; the state change is committed either way, so a broken mailer
     * never repeats the alert on the next run.
     */
    private function notifyAdmins(Notification $notification): void
    {
        if (! config('sakala.email_delivery_enabled')) {
            return;
        }

        DB::afterCommit(static function () use ($notification): void {
            User::query()->where('role', UserRole::Admin)->orderBy('id')->each(static function (User $admin) use ($notification): void {
                try {
                    $admin->notify($notification);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        });
    }
}
